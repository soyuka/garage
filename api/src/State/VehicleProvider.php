<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Vehicle;
use App\Vehicle\VehicleLookup;

/**
 * @implements ProviderInterface<Vehicle>
 */
final class VehicleProvider implements ProviderInterface
{
    public function __construct(private readonly VehicleLookup $vehicleLookup)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Vehicle
    {
        return $this->vehicleLookup->byPlate((string) $uriVariables['plate']);
    }
}
