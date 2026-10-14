<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\RemoveVehicleFactsInput;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Maintenance\VehicleFactWriter;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `remove_vehicle_facts` tool: soft-deletes the named keys on a vehicle addressed by @id. Keys
 * the vehicle does not hold are reported in `notFound` rather than failing the call.
 *
 * @implements ProcessorInterface<RemoveVehicleFactsInput, CallToolResult>
 */
final class RemoveVehicleFactsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleFactWriter $writer,
        private readonly VehicleIriResolver $iriResolver,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $keys = array_values(array_unique(array_filter($data->keys, static fn (mixed $k): bool => \is_string($k) && '' !== trim($k))));
        if ([] === $keys) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'keys must hold at least one fact key.']);
        }

        $resolved = $this->iriResolver->resolve($data->vehicle);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        $vehicle = $this->writer->vehicleOf($resolved);
        if (null === $vehicle) {
            return $this->iriResolver->notFound($data->vehicle);
        }

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => ['@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle))],
        ] + $this->writer->remove($vehicle, $keys));
    }
}
