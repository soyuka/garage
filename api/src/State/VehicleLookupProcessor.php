<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Vehicle;
use App\Bonneref\BonnerefClient;
use App\Dto\PlateInput;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MCP `lookup_plate` tool.
 *
 * @implements ProcessorInterface<PlateInput, Vehicle>
 */
final class VehicleLookupProcessor implements ProcessorInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vehicle
    {
        $vehicle = $this->bonneref->vehicleByPlate($data->plate);

        if (null === $vehicle) {
            throw new NotFoundHttpException(\sprintf('No vehicle found for plate "%s" in the SIV registry, which has no motorbikes (a mistyped plate also lands here). Ask the user for the make, model and year or engine, then resolve the vehicle with list_makes → list_models → list_cars, with carType MC for a motorbike.', $data->plate));
        }

        return $vehicle;
    }
}
