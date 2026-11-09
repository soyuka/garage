<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentTurnRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * The persisted record of one agent turn — the debug journal. A turn is otherwise stateless (the
 * worker carries no history; see {@see \App\MessageHandler\RunAgentTurnHandler}), so without this
 * document everything the agent did vanishes the moment the worker returns. One row per turn captures
 * the instruction, the server-built LLM context, every tool call in order, the web-search spend, the
 * token usage and how the turn settled — the operator's only window into a run that misbehaved.
 *
 * Accumulated live during the turn by {@see \App\Agent\Journal\TurnJournal}; read back, owner-scoped,
 * through the {@see \App\ApiResource\AgentTurn} resource.
 *
 * `owner` is the same multi-tenancy seam as the rest of the garage: the Casdoor `email` claim.
 */
#[ODM\Document(collection: 'agent_turns', repositoryClass: AgentTurnRepository::class)]
// Sort-covering for the journal listing: scoped to owner, newest first.
#[ODM\Index(keys: ['owner' => 'asc', 'startedAt' => 'desc'], name: 'idx_turn_owner_started')]
class AgentTurn
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'string', enumType: AgentTurnStatus::class)]
    private AgentTurnStatus $status = AgentTurnStatus::Failed;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $error = null;

    /** Correlates a failed turn with the logged cause; {@see $error} only carries a public message. */
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $errorId = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $durationMs = null;

    /** @var Collection<int, AgentTurnToolCall> */
    #[ODM\EmbedMany(targetDocument: AgentTurnToolCall::class)]
    private Collection $toolCalls;

    #[ODM\Field(type: 'int')]
    private int $webSearchCount = 0;

    /** @var list<string> */
    #[ODM\Field(type: 'collection')]
    private array $webSearchQueries = [];

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $promptTokens = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $completionTokens = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $totalTokens = null;

    // Anthropic's input_tokens excludes the cached prefix: under prompt caching a turn reads as a few
    // prompt tokens unless the cache read/write buckets are kept alongside it.
    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $cacheReadTokens = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $cacheCreationTokens = null;

    /**
     * Raw LLM call payloads (request/response/usage) for offline analysis. Best-effort: full on the
     * non-stream path, partial on the streamed Anthropic path (the raw body is not readable without
     * draining). One entry per model call across the tool loop.
     *
     * @var list<array<string, mixed>>
     */
    #[ODM\Field(type: 'collection')]
    private array $rawCalls = [];

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $finalText = null;

    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $owner,
        #[ODM\Field(type: 'string')]
        private string $sessionId,
        #[ODM\Field(type: 'string')]
        private string $instruction,
        #[ODM\Field(type: 'string')]
        private string $context,
        #[ODM\Field(type: 'string')]
        private string $provider,
        #[ODM\Field(type: 'string')]
        private string $model,
        #[ODM\Field(type: 'bool')]
        private bool $stream,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $selectedId = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $summary = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $pendingContext = null,
    ) {
        $this->id = (string) new Ulid();
        $this->startedAt = new \DateTimeImmutable();
        $this->toolCalls = new ArrayCollection();
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getInstruction(): string
    {
        return $this->instruction;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isStream(): bool
    {
        return $this->stream;
    }

    /**
     * Fill the provider/model/stream once the platform decorator sees the live model call — the active
     * platform is a compile-time choice in ai.yaml, not known when the turn opens. First call wins:
     * later tool-loop passes hit the same model, so they must not overwrite it with anything weaker.
     */
    public function setProviderModel(string $provider, string $model, bool $stream): void
    {
        if ('' === $this->provider) {
            $this->provider = $provider;
            $this->model = $model;
            $this->stream = $stream;
        }
    }

    public function getSelectedId(): ?string
    {
        return $this->selectedId;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getPendingContext(): ?string
    {
        return $this->pendingContext;
    }

    public function getStatus(): AgentTurnStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorId(): ?string
    {
        return $this->errorId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /**
     * @return list<AgentTurnToolCall>
     */
    public function getToolCalls(): array
    {
        return array_values($this->toolCalls->toArray());
    }

    public function addToolCall(AgentTurnToolCall $toolCall): void
    {
        $this->toolCalls->add($toolCall);
    }

    public function getWebSearchCount(): int
    {
        return $this->webSearchCount;
    }

    /**
     * @return list<string>
     */
    public function getWebSearchQueries(): array
    {
        return $this->webSearchQueries;
    }

    /**
     * @param list<string> $queries
     */
    public function recordWebSearch(int $count, array $queries): void
    {
        $this->webSearchCount += $count;
        foreach ($queries as $query) {
            if ('' !== $query) {
                $this->webSearchQueries[] = $query;
            }
        }
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
    }

    public function getCacheCreationTokens(): ?int
    {
        return $this->cacheCreationTokens;
    }

    /**
     * Accumulate token usage across the turn's model calls. Null deltas are ignored; any non-null one
     * promotes the running total from null to a sum, so a turn with no usage data stays null (unknown)
     * rather than reading a misleading zero.
     */
    public function addUsage(?int $promptTokens, ?int $completionTokens, ?int $cacheReadTokens = null, ?int $cacheCreationTokens = null): void
    {
        $add = static fn (?int $total, ?int $delta): ?int => null === $delta ? $total : ($total ?? 0) + $delta;

        $this->promptTokens = $add($this->promptTokens, $promptTokens);
        $this->completionTokens = $add($this->completionTokens, $completionTokens);
        $this->cacheReadTokens = $add($this->cacheReadTokens, $cacheReadTokens);
        $this->cacheCreationTokens = $add($this->cacheCreationTokens, $cacheCreationTokens);

        if (null !== $promptTokens || null !== $completionTokens || null !== $cacheReadTokens || null !== $cacheCreationTokens) {
            $this->totalTokens = ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0) + ($this->cacheReadTokens ?? 0) + ($this->cacheCreationTokens ?? 0);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRawCalls(): array
    {
        return $this->rawCalls;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addRawCall(array $payload): void
    {
        $this->rawCalls[] = $payload;
    }

    public function getFinalText(): ?string
    {
        return $this->finalText;
    }

    public function setFinalText(?string $finalText): void
    {
        $this->finalText = $finalText;
    }

    public function finish(AgentTurnStatus $status, ?string $error, ?string $errorId = null): void
    {
        $this->status = $status;
        $this->error = $error;
        $this->errorId = $errorId;
        $this->finishedAt = new \DateTimeImmutable();
        $this->durationMs = (int) (($this->finishedAt->format('U.u') - $this->startedAt->format('U.u')) * 1000);
    }
}
