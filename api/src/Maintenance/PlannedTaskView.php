<?php

declare(strict_types=1);

namespace App\Maintenance;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\PlannedTask;
use App\Entity\PlannedMaintenance;
use App\Entity\VehicleFact;

/**
 * Flattens a PlannedMaintenance into the array the MCP tool and the vehicle item GET both surface —
 * mirrors {@see RecordView}. The `@id` is the dereferenceable PlannedTask IRI the dashboard PATCHes
 * to tick the task off; `vehicle` is the owning vehicle's IRI.
 */
final class PlannedTaskView
{
    public function __construct(
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleFactsProjector $facts,
        private readonly PlannedTaskFluidEnricher $fluids,
    ) {
    }

    /**
     * @return array{
     *     @id: string,
     *     type: string,
     *     label: string|null,
     *     target: array{value: int, unit: string|null}|null,
     *     dueDate: string|null,
     *     parts: list<array{ref: string, label?: string, brand?: string, qty?: int, spec?: array{verified: bool, viscosity?: string, standard?: string, capacity?: string, unit?: string}}>,
     *     attributes: list<array{key: string, value: string, unit: string|null, source: string, verified: bool, url: string|null}>,
     *     status: string,
     *     vehicle: string
     * }
     */
    public function toArray(PlannedMaintenance $task): array
    {
        $targetValue = $task->getTargetUsageValue();

        return [
            '@id' => $this->iriConverter->getIriFromResource(PlannedTask::fromEntity($task)),
            'type' => $task->getType(),
            'label' => $task->getLabel(),
            'target' => null !== $targetValue
                ? ['value' => $targetValue, 'unit' => $task->getTargetUsageUnit()?->value]
                : null,
            'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            'parts' => $this->fluids->enrich(
                $task->getType(),
                $task->getParts(),
                $this->facts->forVehicle($task->getVehicle()),
            ),
            'attributes' => array_map(static fn (VehicleFact $f): array => [
                'key' => $f->getKey(),
                'value' => $f->getValue(),
                'unit' => $f->getUnit(),
                'source' => $f->getSource(),
                'verified' => $f->isVerified(),
                'url' => $f->getUrl(),
            ], array_values($task->getAttributes()->toArray())),
            'status' => $task->getStatus()->value,
            'vehicle' => '/garage/vehicles/'.$task->getVehicle()->getId(),
        ];
    }
}
