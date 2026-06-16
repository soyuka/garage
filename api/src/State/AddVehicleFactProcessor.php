<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\AddVehicleFactInput;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `add_vehicle_fact` tool: writes an unverified llm/user fact onto the shared vehicle
 * (creating it if absent). When the target key already holds a verified authoritative fact,
 * returns a structured refusal naming the existing fact — never overwrites, never throws,
 * so the precedence rule cannot leak as a -32603 error.
 *
 * @implements ProcessorInterface<AddVehicleFactInput, CallToolResult>
 */
final class AddVehicleFactProcessor implements ProcessorInterface
{
    public function __construct(private readonly GarageResolver $resolver)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicleId && (null === $data->make || null === $data->model)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicleId from list_vehicles.',
            ]);
        }

        $outcome = $this->resolver->addFact(
            vehicleId: $data->vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            key: $data->key,
            value: $data->value,
            unit: $data->unit,
            source: $data->source,
            url: $data->url,
            addedBy: $data->addedBy,
        );

        return match ($outcome->status) {
            'refused' => ToolResult::of([
                'status' => 'refused',
                'reason' => sprintf(
                    'A verified %s fact already exists for "%s"; it is authoritative and is not overwritten by %s input.',
                    $outcome->fact->getSource(),
                    $data->key,
                    $data->source,
                ),
                'existing' => FactView::toArray($outcome->fact),
            ]),
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call add_vehicle_fact with vehicleId set to the matching candidate to update it, or pass confirmNew:true to store this as a new vehicle.',
                'candidates' => array_map(VehicleSummaryView::toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle with id %d in the garage.', $outcome->vehicleId),
            ]),
            default => ToolResult::of([
                'status' => 'ok',
                'fact' => FactView::toArray($outcome->fact),
            ]),
        };
    }
}
