<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Vehicle;
use App\Dto\PlateInput;
use App\Vehicle\VehicleLookup;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MCP `lookup_plate` tool.
 *
 * @implements ProcessorInterface<PlateInput, Vehicle>
 */
final class VehicleLookupProcessor implements ProcessorInterface
{
    public function __construct(private readonly VehicleLookup $vehicleLookup)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vehicle
    {
        $vehicle = $this->vehicleLookup->byPlate($data->plate);

        if (null === $vehicle) {
            throw new NotFoundHttpException(sprintf('No vehicle found for plate "%s".', $data->plate));
        }

        return $vehicle;
    }
}
