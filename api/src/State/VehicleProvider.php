<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Vehicle;
use App\Bonneref\BonnerefClient;

/**
 * @implements ProviderInterface<Vehicle>
 */
final class VehicleProvider implements ProviderInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Vehicle
    {
        return $this->bonneref->vehicleByPlate((string) $uriVariables['plate']);
    }
}
