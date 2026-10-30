<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Entity\AgentTurn;
use App\Catalog\CarType;
use App\Catalog\CatalogDate;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use Psr\Log\LoggerInterface;

/**
 * carId (TecDoc kType) → vehicle descriptor, learned from every place the app already sees one, so a
 * journal row that only kept the carId can be named at read time without an upstream call (the catalogue
 * has no carId → name method). Global reference data: a kType names the same car for every tenant.
 *
 * Stored in Mongo, not a cache pool: the cache pools are TTL'd key/value stores that cannot be
 * queried or bulk-read, and this index must be read for a whole page of journal rows at once.
 * Writes go straight to the collection (no unit of work), so indexing from inside a request never
 * flushes whatever else the document manager holds. Best-effort: a failure is logged, never thrown.
 *
 * A name from a higher-ranked source is never overwritten by a lower one: the catalogue's own naming
 * beats SIV's, which beats what a page or a model typed.
 *
 * @phpstan-type IndexedVehicle array{carId: int, carType: string, make: ?string, model: ?string, engine: ?string, fullName: ?string, yearFrom: ?string, yearTo: ?string, source: string, seenAt: ?\DateTimeImmutable}
 */
final class VehicleIndex
{
    private const string COLLECTION = 'catalog_vehicles';

    private const array RANKS = ['catalog' => 3, 'siv' => 2, 'finder' => 1, 'journal' => 1];

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(int $carId, string|CarType|null $carType, ?string $make, ?string $model, ?string $engine, string $source, ?string $fullName = null, ?string $yearFrom = null, ?string $yearTo = null): void
    {
        $type = self::type($carType);
        $make = self::text($make);
        $model = self::text($model);
        if ($carId <= 0 || null === $type || null === $make || null === $model) {
            return;
        }

        $rank = self::RANKS[$source] ?? 1;
        try {
            $this->collection()->updateOne(
                ['_id' => self::id($carId, $type), 'rank' => ['$lte' => $rank]],
                ['$set' => self::document($carId, $type, $make, $model, self::text($engine), $source, $rank, self::text($fullName), $yearFrom, $yearTo)],
                ['upsert' => true],
            );
        } catch (BulkWriteException $e) {
            // E11000: a higher-ranked source already named this car.
            if (11000 !== $e->getCode()) {
                $this->logger->warning('Failed to index a vehicle: {error}', ['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to index a vehicle: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A getCars answer: every row names its own car.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function recordCatalogRows(array $rows, CarType $carType): void
    {
        $writes = [];
        foreach ($rows as $row) {
            $carId = (int) ($row['CAR_ID'] ?? 0);
            $make = self::text($row['MAKE_NAME'] ?? null);
            $model = self::text($row['MODEL_NAME'] ?? null);
            if ($carId <= 0 || null === $make || null === $model) {
                continue;
            }
            $writes[] = ['updateOne' => [
                ['_id' => self::id($carId, $carType->value), 'rank' => ['$lte' => self::RANKS['catalog']]],
                ['$set' => self::document($carId, $carType->value, $make, $model, self::text($row['CAR_NAME'] ?? null), 'catalog', self::RANKS['catalog'], self::text($row['FULL_MODEL_NAME'] ?? null), CatalogDate::yearMonth($row['YEAR_START'] ?? null), CatalogDate::yearMonth($row['YEAR_END'] ?? null))],
                ['upsert' => true],
            ]];
        }
        if ([] === $writes) {
            return;
        }

        try {
            $this->collection()->bulkWrite($writes, ['ordered' => false]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to index catalogue vehicles: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return IndexedVehicle|null
     */
    public function find(int $carId, string|CarType|null $carType): ?array
    {
        $type = self::type($carType);
        if (null === $type) {
            return null;
        }
        $row = $this->collection()->findOne(['_id' => self::id($carId, $type)], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        return null === $row ? null : self::hydrate($row);
    }

    /**
     * @param list<array{carId: int, carType: string|null}> $keys
     *
     * @return array<string, IndexedVehicle> keyed "<carId>-<carType>"
     */
    public function findMany(array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $type = self::type($key['carType']);
            if (null !== $type) {
                $ids[self::id($key['carId'], $type)] = true;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $found = [];
        foreach ($this->collection()->find(['_id' => ['$in' => array_keys($ids)]], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $row) {
            $found[(string) $row['_id']] = self::hydrate($row);
        }

        return $found;
    }

    public function forget(int $carId, string|CarType|null $carType): void
    {
        $type = self::type($carType);
        if (null !== $type) {
            $this->collection()->deleteOne(['_id' => self::id($carId, $type)]);
        }
    }

    /**
     * The journal vehicle with the names it lacks filled from the index. A row that already names its
     * car, or whose car is unknown, comes back untouched.
     *
     * @param array<string, mixed>|null                        $vehicle
     * @param array<string, IndexedVehicle>|null               $known   a prefetched {@see findMany()} result
     *
     * @return array<string, mixed>|null
     */
    public function complete(?array $vehicle, ?array $known = null): ?array
    {
        if (null === $vehicle || !self::lacksNames($vehicle)) {
            return $vehicle;
        }
        $type = self::type($vehicle['carType'] ?? null);
        $carId = $vehicle['carId'] ?? null;
        if (!\is_int($carId) || null === $type) {
            return $vehicle;
        }

        try {
            $car = null !== $known ? ($known[self::id($carId, $type)] ?? null) : $this->find($carId, $type);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read the vehicle index: {error}', ['error' => $e->getMessage()]);

            return $vehicle;
        }

        return null === $car ? $vehicle : [...$vehicle, 'make' => $car['make'], 'model' => $car['model'], 'engine' => $vehicle['engine'] ?? $car['engine']];
    }

    /**
     * @param array<string, mixed>|null $vehicle
     */
    public static function lacksNames(?array $vehicle): bool
    {
        return null !== $vehicle && null === ($vehicle['make'] ?? null) && null === ($vehicle['model'] ?? null) && \is_int($vehicle['carId'] ?? null);
    }

    public static function id(int $carId, string $carType): string
    {
        return $carId.'-'.$carType;
    }

    private function collection(): Collection
    {
        return $this->dm->getDocumentDatabase(AgentTurn::class)->selectCollection(self::COLLECTION);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(int $carId, string $carType, string $make, string $model, ?string $engine, string $source, int $rank, ?string $fullName, ?string $yearFrom, ?string $yearTo): array
    {
        return [
            'carId' => $carId,
            'carType' => $carType,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'fullName' => $fullName,
            'yearFrom' => $yearFrom,
            'yearTo' => $yearTo,
            'source' => $source,
            'rank' => $rank,
            'seenAt' => new UTCDateTime(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return IndexedVehicle
     */
    private static function hydrate(array $row): array
    {
        $seenAt = $row['seenAt'] ?? null;

        return [
            'carId' => (int) $row['carId'],
            'carType' => (string) $row['carType'],
            'make' => $row['make'] ?? null,
            'model' => $row['model'] ?? null,
            'engine' => $row['engine'] ?? null,
            'fullName' => $row['fullName'] ?? null,
            'yearFrom' => $row['yearFrom'] ?? null,
            'yearTo' => $row['yearTo'] ?? null,
            'source' => (string) ($row['source'] ?? ''),
            'seenAt' => $seenAt instanceof UTCDateTime ? \DateTimeImmutable::createFromMutable($seenAt->toDateTime()) : null,
        ];
    }

    private static function type(string|CarType|null $carType): ?string
    {
        if ($carType instanceof CarType) {
            return $carType->value;
        }

        return null === $carType ? CarType::PassengerCar->value : CarType::tryFrom($carType)?->value;
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
