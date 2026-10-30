<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\Catalog\CarType;
use App\Vehicle\VehicleIndex;
use Psr\Log\LoggerInterface;

final class IndexedCarMakeResolver implements CarMakeResolver
{
    public function __construct(
        private readonly VehicleIndex $index,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function make(int $carId, CarType $carType): ?string
    {
        try {
            $make = $this->index->find($carId, $carType)['make'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read the vehicle index: {error}', ['error' => $e->getMessage()]);

            return null;
        }

        return \is_string($make) && '' !== $make ? $make : null;
    }
}
