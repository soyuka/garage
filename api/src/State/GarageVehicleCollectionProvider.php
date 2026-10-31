<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\DueMaintenanceView;
use App\Maintenance\VehicleFactsProjector;
use App\Repository\VehicleRepository;

/**
 * Lists the shared garage (the parc the dashboard sidebar shows), newest first — the same set the
 * MCP list_vehicles tool returns, via {@see VehicleRepository::findShared()}. Read-only; the agent
 * and the MCP tools own every write.
 *
 * @implements ProviderInterface<GarageVehicle>
 */
final readonly class GarageVehicleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleFactsProjector $factsProjector,
        private DueMaintenanceCalculator $calculator,
        private DueMaintenanceView $dueView,
    ) {
    }

    /**
     * @return list<GarageVehicle>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // The listing stays light (no inlined facts), but carries factCount and the soonest-due
        // summary so the sidebar shows both. The forecast walks the same records the facts projector
        // already loads per vehicle, so the rail summary costs no extra query.
        return array_map(function (Vehicle $vehicle): GarageVehicle {
            $resource = GarageVehicle::fromEntity($vehicle);
            $resource->factCount = \count($this->factsProjector->forVehicle($vehicle));

            $due = $this->calculator->forVehicle($vehicle)->due;
            $resource->nextDue = [] === $due ? null : $this->dueView->compact($due[0]);

            return $resource;
        }, $this->vehicles->findShared());
    }
}
