<?php

declare(strict_types=1);

namespace App\Finder;

use App\ApiResource\NarrowedParts;
use App\ApiResource\Vehicle;
use App\ApiResource\VinDecoding;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Journals the finder's deterministic searches — plate, VIN, category narrowing — in the agent-turn
 * journal (kind search), so the drawer shows every catalogue spend, not only the agent's. Only the
 * finder sends X-Finder-Rule, and only an authenticated request has an owner to journal under: the
 * dashboard, MCP and anonymous reads pass through untouched. Best-effort: a failed write is logged.
 */
final class FinderSearchJournalListener
{
    public const string RULE_HEADER = 'X-Finder-Rule';

    public const string INPUT_HEADER = 'X-Finder-Input';

    private const string STARTED = '_finder_search_started';

    private const string CALLS = '_finder_search_calls';

    private const array RESOURCES = [NarrowedParts::class, Vehicle::class, VinDecoding::class];

    public function __construct(
        private readonly Security $security,
        private readonly PartsApiClient $partsApi,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->headers->has(self::RULE_HEADER)) {
            return;
        }
        $request->attributes->set(self::STARTED, hrtime(true));
        $request->attributes->set(self::CALLS, $this->partsApi->upstreamCallsByMethod);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $started = $request->attributes->get(self::STARTED);
        if (!\is_int($started) || !\in_array($request->attributes->get('_api_resource_class'), self::RESOURCES, true)) {
            return;
        }
        $owner = $this->security->getUser()?->getUserIdentifier();
        if (null === $owner) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $data = $request->attributes->get('data');
        $search = ['rule' => mb_substr((string) $request->headers->get(self::RULE_HEADER), 0, 40)] + $this->describe($data, $request) + [
            'outcome' => match (true) {
                404 === $status => 'not_found',
                $status >= 400 => 'error',
                $data instanceof NarrowedParts && !$data->narrowed => 'degraded',
                default => 'ok',
            },
            'httpStatus' => $status,
        ];

        $before = $request->attributes->get(self::CALLS, []);
        $spent = [];
        foreach ($this->partsApi->upstreamCallsByMethod as $method => $count) {
            $spent[$method] = $count - ($before[$method] ?? 0);
        }

        // The page URL-encodes the typed text: a header value cannot carry arbitrary UTF-8.
        $input = trim(rawurldecode((string) $request->headers->get(self::INPUT_HEADER, '')));

        try {
            $this->documentManager->persist(AgentTurn::search(
                owner: $owner,
                input: mb_substr('' !== $input ? $input : $request->getPathInfo(), 0, 200),
                search: $search,
                partsApiCallsByMethod: $spent,
                durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
                status: $status < 400 ? AgentTurnStatus::Completed : AgentTurnStatus::Failed,
            ));
            $this->documentManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to journal a finder search: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(mixed $data, Request $request): array
    {
        return match (true) {
            $data instanceof NarrowedParts => [
                'carId' => $data->carId,
                // NarrowedParts carries no carType: the route reads it from the query, PC by default.
                'carType' => (CarType::tryFrom((string) $request->query->get('carType', '')) ?? CarType::PassengerCar)->value,
                'categoryId' => $data->categoryId,
                'clusterCount' => \count($data->clusters),
                'totalArticles' => $data->totalArticles,
                'unclassifiedCount' => $data->unclassifiedCount,
            ],
            $data instanceof Vehicle => [
                'plate' => $data->plate,
                'carId' => $data->carId,
                'carType' => $data->carType,
                'make' => $data->make,
                'model' => $data->model,
            ],
            default => [],
        };
    }
}
