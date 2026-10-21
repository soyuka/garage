<?php

declare(strict_types=1);

namespace App\Finder;

use App\ApiResource\NarrowedParts;
use App\ApiResource\Vehicle;
use App\ApiResource\VinDecoding;
use App\Catalog\PartsCatalog;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\PartsApi\CarType;
use App\Repository\AgentTurnRepository;
use App\Vehicle\VehicleIndex;
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
 * journal (kind search), so the drawer shows every catalogue spend, not only the agent's. Only a
 * user-initiated submit is journaled: the finder sends Search-Rule and Search-Input (the typed
 * text) on a composer submit or chip click, and neither on restores, hash loads and background
 * fetches. The requests of one submit share one Search-Id and fold into one row. Only an
 * authenticated request has an owner to journal under. Best-effort: a failed write is logged.
 */
final class FinderSearchJournalListener
{
    public const string RULE_HEADER = 'Search-Rule';

    public const string INPUT_HEADER = 'Search-Input';

    public const string PLATE_HEADER = 'Search-Plate';

    public const string SEARCH_HEADER = 'Search-Id';

    public const string VEHICLE_HEADER = 'Search-Vehicle';

    private const int MAX_SEARCH_ID_LENGTH = 64;

    private const int MAX_VEHICLE_HEADER_LENGTH = 1000;

    private const int MAX_FIELD_LENGTH = 80;

    private const int MAX_PLATE_LENGTH = 20;

    private const string STARTED = '_finder_search_started';

    private const string CALLS = '_finder_search_calls';

    private const array RESOURCES = [NarrowedParts::class, Vehicle::class, VinDecoding::class];

    public function __construct(
        private readonly Security $security,
        private readonly PartsCatalog $catalog,
        private readonly DocumentManager $documentManager,
        private readonly AgentTurnRepository $turns,
        private readonly VehicleIndex $index,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->headers->has(self::RULE_HEADER) || '' === self::input($request)) {
            return;
        }
        $request->attributes->set(self::STARTED, hrtime(true));
        $request->attributes->set(self::CALLS, $this->catalog->upstreamCallsByMethod());
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $this->indexPageVehicle($request, $event->getResponse()->getStatusCode());
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
                $data instanceof NarrowedParts && $data->groupRequired => 'group_required',
                $data instanceof NarrowedParts && !$data->narrowed => 'degraded',
                default => 'ok',
            },
            'httpStatus' => $status,
        ];

        $before = $request->attributes->get(self::CALLS, []);
        $spent = [];
        foreach ($this->catalog->upstreamCallsByMethod() as $method => $count) {
            $spent[$method] = $count - ($before[$method] ?? 0);
        }

        $searchId = $this->searchId($request);
        $label = $this->categoryLabel($data, $request);
        $failed = $status >= 400;

        try {
            $row = null === $searchId ? null : $this->turns->findOneBySearchId($owner, $searchId);
            if (null !== $row) {
                $row->absorbSearch($search, $spent, $failed ? AgentTurnStatus::Failed : AgentTurnStatus::Completed, $label);
            } else {
                $this->documentManager->persist(AgentTurn::search(
                    owner: $owner,
                    input: mb_substr(self::input($request), 0, 200),
                    search: $search,
                    partsApiCallsByMethod: $spent,
                    durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
                    status: $failed ? AgentTurnStatus::Failed : AgentTurnStatus::Completed,
                    categoryLabel: $label,
                    searchId: $searchId,
                ));
            }
            $this->documentManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to journal a finder search: {error}', ['error' => $e->getMessage()]);
        }
    }

    // Every narrowing the page fires carries the vehicle it shows, journaled or not.
    private function indexPageVehicle(Request $request, int $status): void
    {
        if ($status >= 400 || NarrowedParts::class !== $request->attributes->get('_api_resource_class') || !$request->headers->has(self::VEHICLE_HEADER)) {
            return;
        }
        $header = rawurldecode((string) $request->headers->get(self::VEHICLE_HEADER));
        $descriptor = \strlen($header) > self::MAX_VEHICLE_HEADER_LENGTH ? null : json_decode($header, true);
        if (!\is_array($descriptor) || !\is_int($descriptor['carId'] ?? null)) {
            return;
        }

        $this->index->record($descriptor['carId'], self::text($descriptor['carType'] ?? null), self::text($descriptor['make'] ?? null), self::text($descriptor['model'] ?? null), self::text($descriptor['engine'] ?? null), 'finder');
    }

    // The page URL-encodes the typed text: a header value cannot carry arbitrary UTF-8.
    private static function input(Request $request): string
    {
        return trim(rawurldecode((string) $request->headers->get(self::INPUT_HEADER, '')));
    }

    private function searchId(Request $request): ?string
    {
        $id = trim((string) $request->headers->get(self::SEARCH_HEADER, ''));

        return '' !== $id && \strlen($id) <= self::MAX_SEARCH_ID_LENGTH && 1 === preg_match('/^[A-Za-z0-9-]+$/', $id) ? $id : null;
    }

    private function categoryLabel(mixed $data, Request $request): ?string
    {
        if (!$data instanceof NarrowedParts) {
            return null;
        }
        if ($request->query->has('productGroupId') && null !== $data->productGroup) {
            return $data->productGroup['name'];
        }

        return $this->catalog->cachedCategoryName($data->carId, $data->categoryId, CarType::tryFrom((string) $request->query->get('carType', '')) ?? CarType::PassengerCar);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(mixed $data, Request $request): array
    {
        return match (true) {
            // The narrowing knows only the carId: the page sends the plate it shows so the row reopens it.
            $data instanceof NarrowedParts => self::pageVehicle($request) + [
                'carId' => $data->carId,
                // NarrowedParts carries no carType: the route reads it from the query, PC by default.
                'carType' => (CarType::tryFrom((string) $request->query->get('carType', '')) ?? CarType::PassengerCar)->value,
                'categoryId' => $data->categoryId,
            ] + ($request->query->has('productGroupId') && null !== $data->productGroup ? ['productGroupId' => $data->productGroup['id']] : []) + [
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
                'engine' => self::text(implode(' · ', array_filter([$data->version, $data->engineCode]))),
            ],
            default => [],
        };
    }

    /**
     * What the page shows for the vehicle: the narrowing itself only knows the carId.
     *
     * @return array<string, string>
     */
    private static function pageVehicle(Request $request): array
    {
        $header = rawurldecode((string) $request->headers->get(self::VEHICLE_HEADER, ''));
        $descriptor = '' === $header || \strlen($header) > self::MAX_VEHICLE_HEADER_LENGTH ? null : json_decode($header, true);
        $descriptor = \is_array($descriptor) ? $descriptor : [];

        $fields = [];
        foreach (['plate', 'make', 'model', 'engine'] as $key) {
            $value = self::text($descriptor[$key] ?? null);
            if (null !== $value) {
                $fields[$key] = $value;
            }
        }

        return self::plate($request) + $fields;
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $value));

        return '' === $value ? null : mb_substr($value, 0, self::MAX_FIELD_LENGTH);
    }

    /**
     * @return array{plate?: string}
     */
    private static function plate(Request $request): array
    {
        $plate = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', rawurldecode((string) $request->headers->get(self::PLATE_HEADER, ''))));

        return '' === $plate || mb_strlen($plate) > self::MAX_PLATE_LENGTH ? [] : ['plate' => $plate];
    }
}
