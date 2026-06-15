<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\LogMaintenanceInput;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\MaintenanceType;
use App\Maintenance\RecordView;
use App\Maintenance\UsageUnit;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `log_maintenance` tool: appends a dated service event to a vehicle's history (creating
 * the shared vehicle if unknown). Always a write — never overwrites, so a vehicle accumulates
 * its full maintenance log. Returns the stored record.
 *
 * @implements ProcessorInterface<LogMaintenanceInput, CallToolResult>
 */
final class LogMaintenanceProcessor implements ProcessorInterface
{
    public function __construct(private readonly MaintenanceRecorder $recorder)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $record = $this->recorder->log(
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            type: MaintenanceType::from($data->type),
            performedAt: new \DateTimeImmutable($data->performedAt),
            source: $data->source,
            label: $data->label,
            usageValue: $data->usageValue,
            usageUnit: null !== $data->usageUnit ? UsageUnit::from($data->usageUnit) : null,
            notes: $data->notes,
            costCents: null !== $data->cost ? (int) round(((float) $data->cost) * 100) : null,
            currency: $data->currency,
            performedBy: $data->performedBy,
            partsUsed: $data->partsUsed,
            addedBy: $data->addedBy,
        );

        return ToolResult::of([
            'status' => 'ok',
            'record' => RecordView::toArray($record),
        ]);
    }
}
