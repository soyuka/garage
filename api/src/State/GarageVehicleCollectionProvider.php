<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageVehicle;
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
    public function __construct(private readonly VehicleRepository $vehicles)
    {
    }

    /**
     * @return list<GarageVehicle>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(GarageVehicle::fromEntity(...), $this->vehicles->findShared());
    }
}
