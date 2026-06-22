<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\VehicleForecast;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\DueMaintenanceView;
use App\Maintenance\MaintenanceType;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\Uid\Ulid;

/**
 * Serves GET /garage/vehicles/{vehicleId}/forecast: runs the {@see DueMaintenanceCalculator} over the
 * vehicle and flattens both halves (predicted `due` + honest `gaps`) into the read-model. Owner-gated
 * and soft-delete-aware like {@see GarageVehicleProvider} — a ULID from another tenant or a tombstoned
 * row reads 404, not a cross-owner disclosure.
 *
 * @implements ProviderInterface<VehicleForecast>
 */
final class VehicleForecastProvider implements ProviderInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DueMaintenanceCalculator $calculator,
        private readonly DueMaintenanceView $view,
        private readonly OwnerContext $owner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?VehicleForecast
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            return null;
        }

        $forecast = $this->calculator->forVehicle($vehicle);

        $resource = new VehicleForecast();
        $resource->vehicleId = $vehicle->getId();
        $resource->vehicle = '/garage/vehicles/'.$vehicle->getId();
        $resource->due = array_map($this->view->item(...), $forecast->due);
        $resource->gaps = array_map(static fn (MaintenanceType $t): string => $t->value, $forecast->unknownBaseline);

        return $resource;
    }
}
