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
use App\Exception\UpstreamException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The parts catalogue and the plate registry live in bonneref; this is the one door to them. The
 * caller's Casdoor bearer is forwarded as is: the one the running turn holds ({@see BonnerefToken}) or,
 * outside a turn, the one on the request being served (HTTP and MCP both carry it).
 */
final readonly class BonnerefClient
{
    private const string SOURCE = 'the bonneref catalog';

    public function __construct(
        #[Autowire(service: 'bonneref.client')]
        private HttpClientInterface $http,
        private BonnerefToken $token,
        private RequestStack $requests,
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
        return array_map(Model::fromApi(...), $this->members(sprintf('/makes/%d/models', $makeId), ['carType' => $carType->value]));
    }

    /**
     * @return list<Car>
     */
    public function cars(int $makeId, int $modelId, CarType $carType = CarType::PassengerCar, ?int $year = null, ?float $capacityLitres = null, ?string $fuel = null, ?string $search = null): array
    {
        return array_map(Car::fromApi(...), $this->members(sprintf('/makes/%d/models/%d/cars', $makeId, $modelId), [
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
        return array_map(PartCategory::fromApi(...), $this->members(sprintf('/cars/%d/part-categories', $carId), [
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
        return array_map(Article::fromApi(...), $this->members(sprintf('/cars/%d/part-categories/%d/articles', $carId, $categoryId), ['carType' => $carType->value]));
    }

    public function narrowed(int $carId, int $categoryId, CarType $carType = CarType::PassengerCar, ?string $make = null, ?int $productGroupId = null): NarrowedParts
    {
        return NarrowedParts::fromApi($this->get(sprintf('/cars/%d/part-categories/%d/narrowed', $carId, $categoryId), [
            'carType' => $carType->value,
            'make' => $make,
            'productGroupId' => $productGroupId,
        ]) ?? []);
    }

    public function vehicleByPlate(string $plate): ?Vehicle
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate));
        $row = $this->get('/vehicles/'.rawurlencode($normalised), [], missingIsNull: true);

        return null === $row ? null : Vehicle::fromApi($row);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return list<array<string, mixed>>
     */
    private function members(string $path, array $query): array
    {
        $data = $this->get($path, $query) ?? [];
        $members = $data['hydra:member'] ?? $data['member'] ?? [];

        return array_values(array_filter(\is_array($members) ? $members : [], \is_array(...)));
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>|null null on a 404 when the caller asked for it
     */
    private function get(string $path, array $query, bool $missingIsNull = false): ?array
    {
        $query = array_map(static fn (mixed $v): string => \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, array_filter($query, static fn (mixed $v): bool => null !== $v));
        $headers = ['Accept' => 'application/ld+json'];
        if (null !== $bearer = $this->token->get() ?? BonnerefToken::fromRequest($this->requests->getMainRequest())) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        try {
            $response = $this->http->request('GET', $path, ['query' => $query, 'headers' => $headers]);
            $status = $response->getStatusCode();

            if (404 === $status && $missingIsNull) {
                return null;
            }
            if ($status >= 400) {
                throw $this->failed($path, $status);
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw UpstreamException::transient(self::SOURCE, sprintf('bonneref %s: %s', $path, $e->getMessage()), $e);
        }
    }

    private function failed(string $path, int $status): UpstreamException
    {
        $message = sprintf('bonneref %s answered %d.', $path, $status);

        return $status >= 500 || 429 === $status
            ? UpstreamException::transient(self::SOURCE, $message)
            : UpstreamException::definitive(self::SOURCE, $message);
    }
}
