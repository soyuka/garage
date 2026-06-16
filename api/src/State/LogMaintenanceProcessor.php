<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\LogMaintenanceInput;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\MaintenanceType;
use App\Maintenance\RecordView;
use App\Maintenance\UsageUnit;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `log_maintenance` tool: appends a dated service event to a vehicle's history. Resolves the
 * vehicle through the shared dedup brain, so an ambiguous identity surfaces candidates (status
 * "ambiguous_match") rather than spawning a duplicate. Always a write — never overwrites, so a
 * vehicle accumulates its full maintenance log.
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
        if (null === $data->vehicleId && (null === $data->make || null === $data->model)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicleId from list_vehicles.',
            ]);
        }

        $outcome = $this->recorder->log(
            vehicleId: $data->vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
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

        return match ($outcome->status) {
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call log_maintenance with vehicleId set to the matching candidate, or pass confirmNew:true to log against a new vehicle.',
                'candidates' => array_map(VehicleSummaryView::toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle with id %d in the garage.', $outcome->vehicleId),
            ]),
            default => ToolResult::of([
                'status' => 'ok',
                'record' => RecordView::toArray($outcome->record),
            ]),
        };
    }
}
