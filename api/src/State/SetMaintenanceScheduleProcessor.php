<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\SetMaintenanceScheduleInput;
use App\Entity\ServiceInterval;
use App\Entity\Vehicle;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\ScheduleView;
use App\Maintenance\ServiceSchedulePlanner;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `set_maintenance_schedule` tool: persists a vehicle's recurring service SCHEDULE as a set of
 * {@see ServiceInterval} rules. This is the write path that was missing — without it the agent had
 * only log_maintenance (a past event) and plan_maintenance (one open task), so a multi-cadence
 * manufacturer schedule got flattened into a single record. Resolves the vehicle through the shared
 * dedup brain (ambiguous_match/not_found like the other write tools) and UPSERTS each rule by key.
 *
 * @implements ProcessorInterface<SetMaintenanceScheduleInput, CallToolResult>
 */
final class SetMaintenanceScheduleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ServiceSchedulePlanner $planner,
        private readonly VehicleIriResolver $iriResolver,
        private readonly VehicleSummaryView $summaryView,
        private readonly ScheduleView $scheduleView,
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
                'reason' => 'Provide at least one schedule rule in items.',
            ]);
        }

        $intervals = [];
        foreach ($data->items as $i => $item) {
            $error = $this->validateItem($item, $i);
            if (null !== $error) {
                return ToolResult::of(['status' => 'invalid_input', 'reason' => $error]);
            }
            $intervals[] = $this->interval($item, $data);
        }

        $vehicleId = null;
        if (null !== $data->vehicle) {
            $resolved = $this->iriResolver->resolve($data->vehicle);
            if ($resolved instanceof CallToolResult) {
                return $resolved;
            }
            $vehicleId = $resolved;
        }

        $outcome = $this->planner->setSchedule(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            source: $data->source,
            intervals: $intervals,
        );

        $error = $outcome->toToolResult(
            $this->summaryView,
            'One or more stored vehicles may be the same one. Re-call set_maintenance_schedule with vehicle set to the matching candidate\'s @id, or pass confirmNew:true to write against a new vehicle.',
        );
        if (null !== $error) {
            return $error;
        }

        return $this->published($outcome->vehicle, $outcome->intervals);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function validateItem(array $item, int $index): ?string
    {
        if (null === ($item['type'] ?? null) || '' === $item['type']) {
            return sprintf('items[%d]: a non-empty type is required.', $index);
        }
        if (null === MaintenanceAction::tryFrom((string) ($item['action'] ?? ''))) {
            return sprintf('items[%d]: action must be one of %s.', $index, implode(', ', MaintenanceAction::values()));
        }
        $hasUsage = null !== ($item['everyUsageValue'] ?? null);
        if ($hasUsage !== (null !== ($item['everyUsageUnit'] ?? null))) {
            return sprintf('items[%d]: everyUsageValue and everyUsageUnit must be provided together.', $index);
        }
        if (!$hasUsage && null === ($item['everyMonths'] ?? null) && null === ($item['firstUsageValue'] ?? null)) {
            return sprintf('items[%d]: a rule needs at least one cadence (everyUsageValue, everyMonths or firstUsageValue).', $index);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function interval(array $item, SetMaintenanceScheduleInput $data): ServiceInterval
    {
        $unit = isset($item['everyUsageUnit']) ? UsageUnit::from((string) $item['everyUsageUnit']) : null;

        return ServiceInterval::fromSource(
            type: (string) $item['type'],
            action: MaintenanceAction::from((string) $item['action']),
            source: $data->source,
            component: $item['component'] ?? null,
            everyUsageValue: $item['everyUsageValue'] ?? null,
            everyUsageUnit: $unit,
            everyMonths: $item['everyMonths'] ?? null,
            firstUsageValue: $item['firstUsageValue'] ?? null,
            url: $item['url'] ?? null,
            addedBy: $data->addedBy,
        );
    }

    /**
     * @param list<ServiceInterval> $intervals
     */
    private function published(Vehicle $vehicle, array $intervals): CallToolResult
    {
        // Push the vehicle so the dashboard card reflects the fresh forecast/health badge.
        $this->publisher->publishVehicle($vehicle);

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => '/garage/vehicles/'.$vehicle->getId(),
            'count' => \count($intervals),
            'schedule' => $this->scheduleView->toArray($intervals),
        ]);
    }
}
