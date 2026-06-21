<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\AgentTurn as AgentTurnEntity;
use App\State\AgentTurnCollectionProvider;
use App\State\AgentTurnProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The read-model of the agent-turn debug journal ({@see AgentTurnEntity}). Read-only: a turn is
 * written by the worker, never by a client. Owner-scoped — a user sees only their own runs.
 *
 * The listing (/agent/turns) stays light: summary fields + counts, the heavy context/tool/raw payload
 * omitted so the drawer renders fast. The item (/agent/turns/{id}) inlines everything — the built LLM
 * context, the ordered tool calls, the web-search queries, the token usage and the raw call payloads —
 * so the operator can replay exactly what happened.
 */
#[ApiResource(
    shortName: 'AgentTurn',
    operations: [
        new GetCollection(uriTemplate: '/agent/turns', provider: AgentTurnCollectionProvider::class),
        new Get(uriTemplate: '/agent/turns/{id}', provider: AgentTurnProvider::class),
    ],
)]
final class AgentTurn
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the turn.')]
    public Ulid $id;

    public string $instruction;
    public string $status;
    public ?string $error = null;
    public string $provider;
    public string $model;
    public bool $stream;
    public string $sessionId;
    public ?string $selectedId = null;
    public string $startedAt;
    public ?string $finishedAt = null;
    public ?int $durationMs = null;
    public int $toolCallCount = 0;
    public int $webSearchCount = 0;
    public ?int $totalTokens = null;

    /** Inlined on the item read only. The exact context handed to the LLM. */
    #[ApiProperty(description: 'The server-built context handed to the LLM (item read only).')]
    public ?string $context = null;
    public ?string $summary = null;
    public ?string $pendingContext = null;
    public ?string $finalText = null;
    public ?int $promptTokens = null;
    public ?int $completionTokens = null;
    /** @var list<string> */
    public array $webSearchQueries = [];
    /** @var list<array{seq: int, name: string, arguments: array<string, mixed>, result: string, at: string, serverSide: bool}> */
    #[ApiProperty(description: 'Ordered tool calls the agent made (item read only).')]
    public array $toolCalls = [];
    /** @var list<array<string, mixed>> */
    #[ApiProperty(description: 'Raw LLM call payloads for offline analysis (item read only, best-effort).')]
    public array $rawCalls = [];

    /**
     * Light projection for the listing: identity, status, timing and counts. No heavy payload.
     */
    public static function summaryOf(AgentTurnEntity $turn): self
    {
        $resource = new self();
        $resource->id = $turn->getId();
        $resource->instruction = $turn->getInstruction();
        $resource->status = $turn->getStatus()->value;
        $resource->error = $turn->getError();
        $resource->provider = $turn->getProvider();
        $resource->model = $turn->getModel();
        $resource->stream = $turn->isStream();
        $resource->sessionId = $turn->getSessionId();
        $resource->selectedId = $turn->getSelectedId();
        $resource->startedAt = $turn->getStartedAt()->format(\DateTimeInterface::ATOM);
        $resource->finishedAt = $turn->getFinishedAt()?->format(\DateTimeInterface::ATOM);
        $resource->durationMs = $turn->getDurationMs();
        $resource->toolCallCount = \count($turn->getToolCalls());
        $resource->webSearchCount = $turn->getWebSearchCount();
        $resource->totalTokens = $turn->getTotalTokens();

        return $resource;
    }

    /**
     * Full projection for the item read: the summary plus the heavy payload.
     */
    public static function fullOf(AgentTurnEntity $turn): self
    {
        $resource = self::summaryOf($turn);
        $resource->context = $turn->getContext();
        $resource->summary = $turn->getSummary();
        $resource->pendingContext = $turn->getPendingContext();
        $resource->finalText = $turn->getFinalText();
        $resource->promptTokens = $turn->getPromptTokens();
        $resource->completionTokens = $turn->getCompletionTokens();
        $resource->webSearchQueries = $turn->getWebSearchQueries();
        $resource->toolCalls = array_map(static fn ($call): array => [
            'seq' => $call->getSeq(),
            'name' => $call->getName(),
            'arguments' => $call->getArguments(),
            'result' => $call->getResult(),
            'at' => $call->getAt()->format(\DateTimeInterface::ATOM),
            'serverSide' => $call->isServerSide(),
        ], $turn->getToolCalls());
        $resource->rawCalls = $turn->getRawCalls();

        return $resource;
    }
}
