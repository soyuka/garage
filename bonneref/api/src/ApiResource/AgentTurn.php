<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Agent\Journal\TurnCostEstimator;
use App\Agent\Journal\TurnRoute;
use App\Entity\AgentTurnKind;
use App\Entity\AgentTurn as AgentTurnEntity;
use App\State\AgentTurnCollectionProvider;
use App\State\AgentTurnProvider;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

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
        new GetCollection(
            uriTemplate: '/agent/turns',
            provider: AgentTurnCollectionProvider::class,
            parameters: [
                'surface' => new QueryParameter(
                    description: 'Only the rows of one surface: finder.',
                    schema: ['type' => 'string', 'enum' => ['finder']],
                    constraints: [new Assert\Choice(choices: ['finder'])],
                ),
                'history' => new QueryParameter(
                    description: 'The finder home history: leaves out rows the owner hid or cleared. The journal (no flag) lists everything.',
                    schema: ['type' => 'boolean'],
                ),
            ],
        ),
        new Get(uriTemplate: '/agent/turns/{id}', provider: AgentTurnProvider::class),
    ],
)]
final class AgentTurn
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the turn.')]
    public Ulid $id;

    public string $instruction;
    public string $surface;
    public string $kind;
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
    public ?int $cacheReadTokens = null;
    public ?int $cacheCreationTokens = null;
    #[ApiProperty(description: 'Estimated USD cost from the AGENT_PRICES entry of the turn model; null when that model is not priced.')]
    public ?float $estimatedCostUsd = null;
    /** @var list<array{mimeType: string, bytes: int}> */
    #[ApiProperty(description: 'Shape of the photos the turn carried (type and size, never the bytes).')]
    public array $images = [];
    #[ApiProperty(description: 'partsapi requests actually sent upstream during the row (cache hits are free).')]
    public ?int $partsApiCalls = null;
    /** @var array<string, int> */
    #[ApiProperty(description: 'partsapi upstream requests by method: the test keys are capped per method and per day.')]
    public array $partsApiCallsByMethod = [];
    /** @var array<string, mixed>|null */
    #[ApiProperty(description: 'For a deterministic finder search (kind search): rule, carId, categoryId, clusterCount, totalArticles, unclassifiedCount, outcome, httpStatus.')]
    public ?array $search = null;
    #[ApiProperty(description: 'The finder hash path (without "#/") that reopens what the row searched: p/<plate> or k/<carId>-<carType>, then /c/<categoryId> when a category is known; null when no vehicle is known.')]
    public ?string $route = null;
    #[ApiProperty(description: 'French TecDoc name of the category the row searched or showed (last show_parts of an agent turn; the cached part-category tree for a search); null when unknown.')]
    public ?string $categoryLabel = null;
    /** @var array{plate: ?string, carId: ?int, carType: ?string, make: ?string, model: ?string}|null */
    #[ApiProperty(description: 'The vehicle the row was about: the last set_vehicle of an agent turn, else the vehicle the page sent; for a search, what it resolved.')]
    public ?array $vehicle = null;

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
    public static function summaryOf(AgentTurnEntity $turn, ?TurnCostEstimator $cost = null): self
    {
        $resource = new self();
        $resource->id = $turn->getId();
        $resource->instruction = $turn->getInstruction();
        $resource->surface = $turn->getSurface()->value;
        $resource->kind = $turn->getKind()->value;
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
        $resource->images = $turn->getImages();
        $resource->partsApiCalls = $turn->getPartsApiCalls();
        $resource->partsApiCallsByMethod = $turn->getPartsApiCallsByMethod();
        $resource->search = [] === $turn->getSearch() ? null : $turn->getSearch();
        $route = AgentTurnKind::Search === $turn->getKind()
            ? TurnRoute::forSearch($turn->getSearch())
            : TurnRoute::forAgent($turn->getVehicle(), array_map(static fn ($call): array => ['name' => $call->getName(), 'arguments' => $call->getArguments(), 'result' => $call->getResult()], $turn->getToolCalls()));
        $resource->route = $route->route;
        $resource->vehicle = $route->vehicle;
        $resource->categoryLabel = $turn->getCategoryLabel();
        $resource->cacheReadTokens = $turn->getCacheReadTokens();
        $resource->cacheCreationTokens = $turn->getCacheCreationTokens();
        $resource->estimatedCostUsd = $cost?->estimate($turn->getModel(), $turn->getPromptTokens(), $turn->getCompletionTokens(), $turn->getCacheReadTokens(), $turn->getCacheCreationTokens());

        return $resource;
    }

    /**
     * Full projection for the item read: the summary plus the heavy payload.
     */
    public static function fullOf(AgentTurnEntity $turn, ?TurnCostEstimator $cost = null): self
    {
        $resource = self::summaryOf($turn, $cost);
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
