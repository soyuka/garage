<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlannedTask;
use App\Dto\PlannedTaskInput;
use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Maintenance\CalendarDate;
use App\Maintenance\PlannedMaintenancePlanner;
use App\Mercure\DomainPublisher;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{vehicleId}/planned-tasks — the dashboard's manual task add. Resolves the
 * vehicle by the URI ULID through the shared planner (owner-gated, so a foreign @id is a 404) and
 * upserts an open task, source "user". Returns the fresh tick-offable task and fans the vehicle out
 * over Mercure like every write.
 *
 * @implements ProcessorInterface<PlannedTaskInput, PlannedTask>
 */
final readonly class PlannedTaskCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private PlannedMaintenancePlanner $planner,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlannedTask
    {
        $id = $uriVariables['vehicleId'];
        $vehicleId = $id instanceof Ulid ? $id : Ulid::fromString((string) $id);

        $targetValue = $data->target['value'] ?? null;
        $targetUnit = null;
        if (isset($data->target['unit'])) {
            $targetUnit = UsageUnit::tryFrom((string) $data->target['unit']);
            if (null === $targetUnit) {
                throw new UnprocessableEntityHttpException(\sprintf('Unknown target unit "%s"; expected km, mi or h.', $data->target['unit']));
            }
        }

        $outcome = $this->planner->plan(
            vehicleId: $vehicleId,
            make: null,
            model: null,
            year: null,
            // Placeholder: the vehicle resolves by @id, so its type is read from the row, not this.
            vehicleType: VehicleType::Car,
            carId: null,
            plate: null,
            vin: null,
            confirmNew: false,
            type: $data->type,
            source: 'user',
            label: $data->label,
            targetUsageValue: $targetValue,
            targetUsageUnit: $targetUnit,
            dueDate: CalendarDate::parseNullable($data->dueDate),
            parts: $data->parts,
        );

        if ('not_found' === $outcome->status) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $task = $outcome->task;
        $this->publisher->publishVehicle($task->getVehicle());

        return PlannedTask::fromEntity($task);
    }
}
