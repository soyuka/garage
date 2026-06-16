<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ListVehiclesInput;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_vehicles` tool: enumerates the shared garage (owner IS NULL), optionally filtered
 * by make/vehicleType, as identity summaries with a factCount. An empty garage is an ok result
 * with count 0 — never a miss or an error.
 *
 * @implements ProcessorInterface<ListVehiclesInput, CallToolResult>
 */
final class ListVehiclesProcessor implements ProcessorInterface
{
    public function __construct(private readonly VehicleRepository $vehicles)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $vehicles = $this->vehicles->findShared(
            $data->make,
            $data->model,
            null !== $data->vehicleType ? VehicleType::from($data->vehicleType) : null,
        );

        $summaries = array_map(VehicleSummaryView::toArray(...), $vehicles);

        return ToolResult::of([
            'status' => 'ok',
            'count' => \count($summaries),
            'vehicles' => $summaries,
        ]);
    }
}
