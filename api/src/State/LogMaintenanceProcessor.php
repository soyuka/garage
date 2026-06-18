<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\LogMaintenanceInput;
use App\Entity\MaintenanceRecord;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\MaintenanceType;
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
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleSummaryView $summaryView,
        private readonly RecordView $recordView,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle && (null === $data->make || null === $data->model)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.',
            ]);
        }

        $vehicleId = null;
        if (null !== $data->vehicle) {
            try {
                $vehicleId = $this->iriConverter->getResourceFromIri($data->vehicle)->id;
            } catch (ItemNotFoundException) {
                return ToolResult::of([
                    'status' => 'not_found',
                    'reason' => sprintf('No vehicle at %s in the garage.', $data->vehicle),
                ]);
            } catch (\Throwable) {
                return ToolResult::of([
                    'status' => 'invalid_input',
                    'reason' => sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $data->vehicle),
                ]);
            }
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
                'reason' => 'One or more stored vehicles may be the same one. Re-call log_maintenance with vehicle set to the matching candidate\'s @id, or pass confirmNew:true to log against a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle %s in the garage.', $outcome->vehicleId),
            ]),
            default => $this->publishedRecord($outcome->record),
        };
    }

    private function publishedRecord(MaintenanceRecord $record): CallToolResult
    {
        // Logging a service can auto-close a matching open task; publishing the whole vehicle ships
        // that updated checklist alongside the new history entry in one frame.
        $this->publisher->publishVehicle($record->getVehicle());

        return ToolResult::of([
            'status' => 'ok',
            'record' => $this->recordView->toArray($record),
        ]);
    }
}
