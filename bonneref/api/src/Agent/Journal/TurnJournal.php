<?php

declare(strict_types=1);

namespace App\Agent\Journal;

use App\Agent\WebSearch\WebSearchTracer;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Entity\AgentTurnToolCall;
use App\Message\RunAgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Accumulates the debug journal of the turn in flight and persists it on settle. A turn-scoped
 * collector: a long-lived singleton in the consume worker (like {@see \App\Agent\WebSearchBudget}),
 * so {@see self::begin()} opens a fresh {@see AgentTurn} each turn and the prior turn's state never
 * leaks in. The capture seams feed it from where each datum is actually observable:
 *  - {@see \App\MessageHandler\RunAgentTurnHandler} opens (begin) and settles (finish) the turn;
 *  - {@see ToolCallJournalListener} records each tool call off the ToolCallsExecuted event;
 *  - {@see \App\Agent\ServerToolUseLogger} — the platform
 *    decorators that see the model on the wire — report the live provider/model (and, for Anthropic,
 *    the server-tool web-search spend).
 *
 * Best-effort throughout: a journal failure is logged and swallowed, never turning observability into
 * a turn-killing error. Recording before {@see self::begin()} (or after {@see self::finish()}) is a
 * no-op — the seams fire on their own schedule and must not assume a turn is open.
 */
final class TurnJournal implements WebSearchTracer
{
    private ?AgentTurn $current = null;
    private int $toolSeq = 0;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new Clock(),
    ) {
    }

    public function begin(RunAgentTurn $message, string $context): void
    {
        // A retried message keeps its id: the attempt continues the row the page already knows.
        $existing = null === $message->turnId ? null : $this->documentManager->find(AgentTurn::class, $message->turnId);
        if ($existing instanceof AgentTurn) {
            $this->current = $existing;
            $this->toolSeq = \count($existing->getToolCalls());

            return;
        }
        $this->toolSeq = 0;
        $this->current = new AgentTurn(
            owner: $message->owner,
            sessionId: $this->sessionId($message->sessionTopic),
            instruction: $message->instruction,
            context: $context,
            // The provider/model are filled by the platform decorator on the first model call (the
            // active platform is a compile-time choice in ai.yaml, not visible here). Empty until then.
            provider: '',
            model: '',
            stream: false,
            summary: '' !== $message->summary ? $message->summary : null,
            pendingContext: '' !== $message->pendingContext ? $message->pendingContext : null,
            id: $message->turnId,
            startedAt: $this->clock->now(),
        );
        $this->current->setSurface($message->surface);
        $this->current->setImages($message->images);
        if (null !== $message->vehicle) {
            $this->current->setVehicle([
                'carId' => $message->vehicle->carId,
                'carType' => $message->vehicle->carType->value,
                'plate' => $message->vehicle->plate,
                'make' => $message->vehicle->make,
                'model' => $message->vehicle->model,
            ]);
        }
    }

    public function currentTurnId(): ?string
    {
        return null === $this->current ? null : (string) $this->current->getId();
    }

    public function recordModel(string $provider, string $model, bool $stream): void
    {
        $this->current?->setProviderModel($provider, $model, $stream);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function recordToolCall(string $name, array $arguments, mixed $result, bool $serverSide = false): void
    {
        $this->current?->addToolCall(new AgentTurnToolCall(
            seq: $this->toolSeq++,
            name: $name,
            arguments: $arguments,
            result: $this->stringify($result),
            at: $this->clock->now(),
            serverSide: $serverSide,
        ));
    }

    /**
     * @param list<string> $queries
     */
    public function recordWebSearch(int $count, array $queries = []): void
    {
        $this->current?->recordWebSearch($count, $queries);
    }

    /**
     * Raw web-search response, tagged so it is identifiable among the turn's raw payloads. Goes into the
     * same `rawCalls` bucket the frontend journal already renders. Its usage is billed, so it is recorded
     * as side-call usage, apart from the orchestrator's tokens. Best-effort: a no-op when no turn is open.
     *
     * @param array<string, mixed> $rawBody
     */
    public function recordWebSearchResponse(string $backend, string $query, array $rawBody, string $model = ''): void
    {
        $usage = 'gemini' === $backend ? ModelUsage::fromGemini($rawBody) : ModelUsage::fromAnthropic($rawBody);
        if ('' === $model) {
            $model = \is_string($rawBody['model'] ?? null) ? $rawBody['model'] : $backend;
        }
        $this->current?->addSideCallUsage($model, $usage);

        $this->current?->addRawCall([
            'kind' => 'web_search',
            'backend' => $backend,
            'query' => $query,
            'response' => $rawBody,
        ]);
    }

    /**
     * @param array<string, mixed> $payload raw request/response/usage for offline analysis
     */
    public function recordLlmCall(array $payload, ?int $promptTokens, ?int $completionTokens, ?int $cacheReadTokens = null, ?int $cacheCreationTokens = null): void
    {
        if (null === $this->current) {
            return;
        }
        $this->current->addUsage($promptTokens, $completionTokens, $cacheReadTokens, $cacheCreationTokens);
        if ([] !== $payload) {
            $this->current->addRawCall($payload);
        }
    }

    public function setFinalText(?string $text): void
    {
        if (null !== $text && '' !== $text) {
            $this->current?->setFinalText($text);
        }
    }

    public function finish(AgentTurnStatus $status, ?string $error, ?string $errorId = null): void
    {
        $turn = $this->current;
        $this->current = null;
        if (null === $turn) {
            return;
        }

        $turn->finish($status, $error, $errorId, $this->clock->now());

        try {
            $this->documentManager->persist($turn);
            $this->documentManager->flush();
        } catch (\Throwable $e) {
            // The journal must never sink a turn. Losing one debug record is acceptable; aborting the
            // worker's settle path (and the Mercure `end` cue with it) is not.
            $this->logger->error('Failed to persist agent turn journal: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function sessionId(string $sessionTopic): string
    {
        // The topic is an absolute Mercure URL whose `topic` query param is /agent/sessions/<id>.
        if (preg_match('#/agent/sessions/([^/&?]+)#', $sessionTopic, $m)) {
            return urldecode($m[1]);
        }

        return $sessionTopic;
    }

    private function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        try {
            return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return get_debug_type($value);
        }
    }
}
