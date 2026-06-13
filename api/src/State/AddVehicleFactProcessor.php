<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\AddVehicleFactInput;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
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
        $outcome = $this->resolver->addFact(
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            key: $data->key,
            value: $data->value,
            unit: $data->unit,
            source: $data->source,
            url: $data->url,
            addedBy: $data->addedBy,
        );

        if ($outcome->refused) {
            return ToolResult::of([
                'status' => 'refused',
                'reason' => sprintf(
                    'A verified %s fact already exists for "%s"; it is authoritative and is not overwritten by %s input.',
                    $outcome->fact->getSource(),
                    $data->key,
                    $data->source,
                ),
                'existing' => FactView::toArray($outcome->fact),
            ]);
        }

        return ToolResult::of([
            'status' => 'ok',
            'fact' => FactView::toArray($outcome->fact),
        ]);
    }
}
