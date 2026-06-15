<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\GetMaintenanceHistoryInput;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\MaintenanceType;
use App\Maintenance\RecordView;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_maintenance_history` tool: returns a vehicle's service events, newest first,
 * optionally filtered by type. An unknown vehicle is a no_records RESULT (isError=false),
 * never an exception — same "miss is not an error" contract as the garage tools.
 *
 * @implements ProcessorInterface<GetMaintenanceHistoryInput, CallToolResult>
 */
final class GetMaintenanceHistoryProcessor implements ProcessorInterface
{
    public function __construct(private readonly MaintenanceRecorder $recorder)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $records = $this->recorder->history(
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            type: null !== $data->type ? MaintenanceType::from($data->type) : null,
            limit: $data->limit,
        );

        $identity = [
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'vehicleType' => $data->vehicleType,
        ];

        if (null === $records) {
            return ToolResult::of([
                'status' => 'no_records',
                'vehicle' => $identity,
            ]);
        }

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => $identity,
            'records' => array_map(RecordView::toArray(...), $records),
        ]);
    }
}
