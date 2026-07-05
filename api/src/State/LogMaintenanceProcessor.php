<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\LogMaintenanceInput;
use App\Entity\MaintenanceRecord;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\RecordView;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
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
    public function __construct(
        private readonly MaintenanceRecorder $recorder,
        private readonly VehicleIriResolver $iriResolver,
        private readonly VehicleSummaryView $summaryView,
        private readonly RecordView $recordView,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle) {
            $guard = $this->iriResolver->requireIdentity($data->make, $data->model);
            if (null !== $guard) {
                return $guard;
            }
        }

        if ([] === $data->items) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'The services go in the `items` array (not partsUsed/services) — one entry per service covered. Example: "items": [{"type": "oil_change", "parts": []}, {"type": "oil_filter", "parts": [{"ref": "W 712/73"}]}]. Provide at least one item, each with a "type".',
            ]);
        }

        $vehicleId = null;
        if (null !== $data->vehicle) {
            $resolved = $this->iriResolver->resolve($data->vehicle);
            if ($resolved instanceof CallToolResult) {
                return $resolved;
            }
            $vehicleId = $resolved;
        }

        $outcome = $this->recorder->log(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            items: $data->items,
            performedAt: new \DateTimeImmutable($data->performedAt ?? 'now'),
            source: $data->source,
            label: $data->label,
            usageValue: $data->usageValue,
            usageUnit: null !== $data->usageUnit ? UsageUnit::from($data->usageUnit) : null,
            notes: $data->notes,
            costCents: null !== $data->cost ? (int) round(((float) $data->cost) * 100) : null,
            currency: $data->currency,
            performedBy: $data->performedBy,
            addedBy: $data->addedBy,
        );

        $error = $outcome->toToolResult(
            $this->summaryView,
            'One or more stored vehicles may be the same one. Re-call log_maintenance with vehicle set to the matching candidate\'s @id, or pass confirmNew:true to log against a new vehicle.',
        );
        if (null !== $error) {
            return $error;
        }

        return $this->publishedRecord($outcome->record);
    }

    private function publishedRecord(MaintenanceRecord $record): CallToolResult
    {
        // Logging a service can auto-close a matching open task and shift the forecast; publishing the
        // vehicle ships the recomputed facts/health badge. The checklist itself is a separate
        // subresource (GET /garage/vehicles/{id}/planned-tasks) the dashboard refetches, not in this frame.
        $this->publisher->publishVehicle($record->getVehicle());

        return ToolResult::of([
            'status' => 'ok',
            'record' => $this->recordView->toArray($record),
        ]);
    }
}
