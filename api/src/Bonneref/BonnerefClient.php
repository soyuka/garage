<?php

declare(strict_types=1);

namespace App\Bonneref;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\NarrowedParts;
use App\ApiResource\PartCategory;
use App\ApiResource\Vehicle;
use App\Catalog\CarType;
use App\Exception\NotConfiguredException;
use App\Exception\UpstreamException;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The parts catalogue and the plate registry live in bonneref; this is the one door to them. The
 * caller's Casdoor bearer is forwarded as is: the one the running turn holds ({@see BonnerefToken}) or,
 * outside a turn, the one on the request being served (HTTP and MCP both carry it). The catalogue answers in the
 * caller's language, sent as `Accept-Language`: the request's, or the turn's interface language in the worker.
 */
final readonly class BonnerefClient
{
    private const string SOURCE = 'the bonneref catalog';

    /** Bounds on following `hydra:next`: bonneref pages its large collections (064a362). */
    public const int MAX_PAGES = 10;

    public const int MAX_ITEMS = 10000;

    public function __construct(
        #[Autowire(service: 'bonneref.client')]
        private HttpClientInterface $http,
        #[\SensitiveParameter]
        private BonnerefToken $token,
        private RequestStack $requests,
        private LoggerInterface $logger = new NullLogger(),
        private ?LocaleContext $locale = null,
    ) {
    }

    /**
     * @return list<Make>
     */
    public function makes(CarType $carType = CarType::PassengerCar, ?string $search = null): array
    {
        return array_map(Make::fromApi(...), $this->members('/makes', ['carType' => $carType->value, 'search' => $search]));
    }

    /**
     * @return list<Model>
     */
    public function models(int $makeId, CarType $carType = CarType::PassengerCar): array
    {
        return array_map(Model::fromApi(...), $this->members(\sprintf('/makes/%d/models', $makeId), ['carType' => $carType->value]));
    }

    /**
     * @return list<Car>
     */
    public function cars(int $makeId, int $modelId, CarType $carType = CarType::PassengerCar, ?int $year = null, ?float $capacityLitres = null, ?string $fuel = null, ?string $search = null): array
    {
        return array_map(Car::fromApi(...), $this->members(\sprintf('/makes/%d/models/%d/cars', $makeId, $modelId), [
            'carType' => $carType->value,
            'year' => $year,
            'capacityLitres' => $capacityLitres,
            'fuel' => $fuel,
            'search' => $search,
        ]));
    }

    /**
     * @return list<PartCategory>
     */
    public function partCategories(int $carId, CarType $carType = CarType::PassengerCar, bool $leafOnly = true, ?string $search = null): array
    {
        return array_map(PartCategory::fromApi(...), $this->members(\sprintf('/cars/%d/part-categories', $carId), [
            'carType' => $carType->value,
            'leafOnly' => $leafOnly,
            'search' => $search,
        ]));
    }

    /**
     * @return list<Article>
     */
    public function articles(int $carId, int $categoryId, CarType $carType = CarType::PassengerCar): array
    {
        return array_map(Article::fromApi(...), $this->members(\sprintf('/cars/%d/part-categories/%d/articles', $carId, $categoryId), ['carType' => $carType->value]));
    }

    public function narrowed(int $carId, int $categoryId, CarType $carType = CarType::PassengerCar, ?string $make = null, ?int $productGroupId = null): NarrowedParts
    {
        return NarrowedParts::fromApi($this->get(\sprintf('/cars/%d/part-categories/%d/narrowed', $carId, $categoryId), [
            'carType' => $carType->value,
            'make' => $make,
            'productGroupId' => $productGroupId,
        ]) ?? []);
    }

    public function vehicleByPlate(string $plate): ?Vehicle
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate));
        $row = $this->get('/vehicles/'.rawurlencode($normalised), [], missingIsNull: true, feature: 'plate lookup');

        return null === $row ? null : Vehicle::fromApi($row);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return list<array<array-key, mixed>>
     */
    private function members(string $path, array $query): array
    {
        $all = [];
        $page = 0;
        while (true) {
            ++$page;
            $data = $this->get($path, $query) ?? [];
            $members = $data['hydra:member'] ?? $data['member'] ?? [];
            foreach (\is_array($members) ? $members : [] as $member) {
                if (\is_array($member)) {
                    $all[] = $member;
                }
            }

            $next = self::nextQuery($data, $path);
            if (null === $next) {
                return $all;
            }
            if ($page >= self::MAX_PAGES || \count($all) >= self::MAX_ITEMS) {
                $this->logger->warning('bonneref {path}: stopped after {pages} pages / {items} items, the collection has more.', ['path' => $path, 'pages' => $page, 'items' => \count($all)]);

                return $all;
            }
            $query = $next;
        }
    }

    /**
     * The query of the next page, when the Hydra view links one on this same collection. A link to
     * another host or path is not followed: the bearer rides every request.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, scalar|null>|null
     */
    private static function nextQuery(array $data, string $path): ?array
    {
        $view = $data['hydra:view'] ?? $data['view'] ?? null;
        $next = \is_array($view) ? ($view['hydra:next'] ?? $view['next'] ?? null) : null;
        if (!\is_string($next)) {
            return null;
        }

        $parts = parse_url($next);
        if (false === $parts || isset($parts['host']) || ($parts['path'] ?? '') !== $path) {
            return null;
        }
        parse_str($parts['query'] ?? '', $parsed);

        $query = [];
        foreach ($parsed as $key => $value) {
            if (\is_string($value)) {
                // @mago-expect analysis:redundant-cast parse_str turns a numeric key into an int
                $query[(string) $key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param array<string, scalar|null> $query
     * @param string                     $feature what a 501 (bonneref has no provider key for it) reports as not configured
     *
     * @return array<array-key, mixed>|null null on a 404 when the caller asked for it
     */
    private function get(string $path, array $query, bool $missingIsNull = false, string $feature = 'this catalogue feature'): ?array
    {
        $query = array_map(static fn (mixed $v): string => match ($v) {
            true => 'true',
            false => 'false',
            default => (string) $v,
        }, array_filter($query, static fn (mixed $v): bool => null !== $v));
        $headers = ['Accept' => 'application/ld+json', 'Accept-Language' => ($this->locale?->current() ?? Locale::DEFAULT)->value];
        if (null !== $bearer = $this->token->get() ?? BonnerefToken::fromRequest($this->requests->getMainRequest())) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        try {
            $response = $this->http->request('GET', $path, ['query' => $query, 'headers' => $headers]);
            $status = $response->getStatusCode();

            if (404 === $status && $missingIsNull) {
                return null;
            }
            if (501 === $status) {
                throw NotConfiguredException::onCatalogueServer($feature);
            }
            if ($status >= 400) {
                throw $this->failed($path, $status);
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw UpstreamException::transient(self::SOURCE, \sprintf('bonneref %s: %s', $path, $e->getMessage()), $e);
        }
    }

    private function failed(string $path, int $status): UpstreamException
    {
        $message = \sprintf('bonneref %s answered %d.', $path, $status);

        return $status >= 500 || 429 === $status
            ? UpstreamException::transient(self::SOURCE, $message)
            : UpstreamException::definitive(self::SOURCE, $message);
    }
}
