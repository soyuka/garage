<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ServiceScheduleItem;
use App\Entity\ServiceInterval;
use App\Repository\VehicleRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Lists a vehicle's live recurring rules at /garage/vehicles/{vehicleId}/service-schedule — the
 * dashboard's editable schedule card reads it. Owner-gated and soft-delete-aware like the other
 * garage providers: a ULID from another tenant or a tombstoned vehicle reads as an empty list.
 *
 * @implements ProviderInterface<ServiceScheduleItem>
 */
final readonly class ServiceScheduleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
    ) {
    }

    /**
     * @return list<ServiceScheduleItem>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->findOwned($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            return [];
        }

        return array_map(
            static fn (ServiceInterval $i): ServiceScheduleItem => ServiceScheduleItem::fromEntity($i, $vehicle->getId()),
            $vehicle->serviceSchedule(),
        );
    }
}
