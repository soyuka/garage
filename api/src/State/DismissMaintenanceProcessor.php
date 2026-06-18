<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\DismissMaintenanceInput;
use App\Entity\PlannedMaintenance;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\PlannedMaintenancePlanner;
use App\Maintenance\PlannedTaskView;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `dismiss_maintenance` tool: the soft-remove counterpart of plan_maintenance. Resolves the
 * vehicle through the shared dedup brain (ambiguous_match/not_found like the other write tools), then
 * flips the matching open task to Dismissed — it leaves the live checklist but stays in the DB. A
 * miss on the (type, label) key is task_not_found, not an error: there was simply nothing to remove.
 *
 * @implements ProcessorInterface<DismissMaintenanceInput, CallToolResult>
 */
final class DismissMaintenanceProcessor implements ProcessorInterface
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

        $outcome = $this->planner->dismiss(
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
            source: 'user',
            label: $data->label,
        );

        return match ($outcome->status) {
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call dismiss_maintenance with vehicle set to the matching candidate\'s @id, or pass confirmNew:true.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle %s in the garage.', $outcome->vehicleId),
            ]),
            'task_not_found' => ToolResult::of([
                'status' => 'task_not_found',
                'reason' => sprintf('No open task "%s"%s on this vehicle\'s checklist — nothing to remove.', $data->type, null !== $data->label ? sprintf(' (%s)', $data->label) : ''),
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
