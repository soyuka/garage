<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PlanMaintenanceInput;
use App\Entity\PlannedMaintenance;
use App\Garage\ToolResult;
use App\Garage\UsageUnit;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceType;
use App\Maintenance\PlannedMaintenancePlanner;
use App\Maintenance\PlannedTaskView;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `plan_maintenance` tool: records intended work as an open task on the vehicle's checklist,
 * carrying the parts/fluids it needs (the shopping list). Resolves the vehicle through the shared
 * dedup brain (ambiguous_match/not_found like the other write tools) and UPSERTS: re-planning the
 * same service updates the one open task rather than duplicating it.
 *
 * @implements ProcessorInterface<PlanMaintenanceInput, CallToolResult>
 */
final class PlanMaintenanceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PlannedMaintenancePlanner $planner,
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleSummaryView $summaryView,
        private readonly PlannedTaskView $taskView,
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

        if ((null === $data->targetUsageValue) !== (null === $data->targetUsageUnit)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'targetUsageValue and targetUsageUnit must be provided together.',
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

        $outcome = $this->planner->plan(
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
            source: $data->source,
            label: $data->label,
            targetUsageValue: $data->targetUsageValue,
            targetUsageUnit: null !== $data->targetUsageUnit ? UsageUnit::from($data->targetUsageUnit) : null,
            dueDate: null !== $data->dueDate ? new \DateTimeImmutable($data->dueDate) : null,
            parts: $data->parts,
            addedBy: $data->addedBy,
        );

        return match ($outcome->status) {
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call plan_maintenance with vehicle set to the matching candidate\'s @id, or pass confirmNew:true to plan against a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle %s in the garage.', $outcome->vehicleId),
            ]),
            default => $this->publishedTask($outcome->task),
        };
    }

    private function publishedTask(PlannedMaintenance $task): CallToolResult
    {
        $this->publisher->publishVehicle($task->getVehicle());

        return ToolResult::of([
            'status' => 'ok',
            'task' => $this->taskView->toArray($task),
        ]);
    }
}
