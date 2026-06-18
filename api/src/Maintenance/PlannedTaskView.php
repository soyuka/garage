<?php

declare(strict_types=1);

namespace App\Maintenance;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\PlannedTask;
use App\Entity\PlannedMaintenance;

/**
 * Flattens a PlannedMaintenance into the array the MCP tool and the vehicle item GET both surface —
 * mirrors {@see RecordView}. The `@id` is the dereferenceable PlannedTask IRI the dashboard PATCHes
 * to tick the task off; `vehicle` is the owning vehicle's IRI.
 */
final class PlannedTaskView
{
    public function __construct(private readonly IriConverterInterface $iriConverter)
    {
    }

    /**
     * @return array{
     *     @id: string,
     *     type: string,
     *     label: string|null,
     *     target: array{value: int, unit: string|null}|null,
     *     dueDate: string|null,
     *     parts: list<array{ref: string, label?: string, brand?: string, qty?: int}>,
     *     status: string,
     *     vehicle: string
     * }
     */
    public function toArray(PlannedMaintenance $task): array
    {
        $targetValue = $task->getTargetUsageValue();

        return [
            '@id' => $this->iriConverter->getIriFromResource(PlannedTask::fromEntity($task)),
            'type' => $task->getType()->value,
            'label' => $task->getLabel(),
            'target' => null !== $targetValue
                ? ['value' => $targetValue, 'unit' => $task->getTargetUsageUnit()?->value]
                : null,
            'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            'parts' => $task->getParts(),
            'status' => $task->getStatus()->value,
            'vehicle' => '/garage/vehicles/'.$task->getVehicle()->getId(),
        ];
    }
}
