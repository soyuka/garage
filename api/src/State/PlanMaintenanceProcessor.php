<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PlanMaintenanceInput;
use App\Entity\PlannedMaintenance;
use App\Garage\ToolResult;
use App\Garage\UsageUnit;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\CalendarDate;
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
final readonly class PlanMaintenanceProcessor implements ProcessorInterface
{
    public function __construct(
        private PlannedMaintenancePlanner $planner,
        private VehicleIriResolver $iriResolver,
        private VehicleSummaryView $summaryView,
        private PlannedTaskView $taskView,
        private DomainPublisher $publisher,
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

        if ((null === $data->targetUsageValue) !== (null === $data->targetUsageUnit)) {
            return ToolResult::error('invalid_input', 'targetUsageValue and targetUsageUnit must be provided together.');
        }

        $vehicleId = null;
        if (null !== $data->vehicle) {
            $resolved = $this->iriResolver->resolve($data->vehicle);
            if ($resolved instanceof CallToolResult) {
                return $resolved;
            }
            $vehicleId = $resolved;
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
            type: $data->type,
            source: $data->source,
            label: $data->label,
            targetUsageValue: $data->targetUsageValue,
            targetUsageUnit: null !== $data->targetUsageUnit ? UsageUnit::from($data->targetUsageUnit) : null,
            dueDate: CalendarDate::parseNullable($data->dueDate),
            parts: $data->parts,
            addedBy: $data->addedBy,
        );

        $error = $outcome->toToolResult(
            $this->summaryView,
            'One or more stored vehicles may be the same one. Re-call plan_maintenance with vehicle set to the matching candidate\'s @id, or pass confirmNew:true to plan against a new vehicle.',
        );
        if (null !== $error) {
            return $error;
        }

        return $this->publishedTask($outcome->plannedTask());
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
