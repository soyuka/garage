<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Maintenance\VehicleFactsProjector;
use App\Repository\VehicleRepository;

/**
 * Lists the shared garage (the parc the dashboard sidebar shows), newest first — the same set the
 * MCP list_vehicles tool returns, via {@see VehicleRepository::findShared()}. Read-only; the agent
 * and the MCP tools own every write.
 *
 * @implements ProviderInterface<GarageVehicle>
 */
final class GarageVehicleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly VehicleFactsProjector $factsProjector,
    ) {
    }

    /**
     * @return list<GarageVehicle>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // The listing stays light (no inlined facts), but carries factCount so the sidebar shows it.
        return array_map(function (Vehicle $vehicle): GarageVehicle {
            $resource = GarageVehicle::fromEntity($vehicle);
            $resource->factCount = \count($this->factsProjector->forVehicle($vehicle));

            return $resource;
        }, $this->vehicles->findShared());
    }
}
