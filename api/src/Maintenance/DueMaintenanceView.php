<?php

declare(strict_types=1);

namespace App\Maintenance;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;

/**
 * Flattens the forecast into the arrays the list_due_maintenance tool surfaces. A due item
 * carries its vehicle identity (the listing is garage-wide) with the dereferenceable `@id`,
 * the time-based due date, and the usage interval as advisory data so the host can ask the
 * user for a current reading.
 */
final class DueMaintenanceView
{
    public function __construct(private readonly IriConverterInterface $iriConverter)
    {
    }

    /**
     * @return array{@id: string, make: string, model: string, year: int|null, vehicleType: string}
     */
    public function identity(Vehicle $vehicle): array
    {
        return [
            '@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle)),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dueItem(Vehicle $vehicle, DueMaintenance $due): array
    {
        // The usage axis (engine-hours/km): what the schedule-driven forecast actually measures.
        $usage = null === $due->usageUnit || null === $due->dueUsageValue ? null : [
            'last' => $due->lastUsageValue,
            'due' => $due->dueUsageValue,
            'remaining' => $due->usageRemaining,
            'unit' => $due->usageUnit->value,
        ];

        return [
            'vehicle' => $this->identity($vehicle),
            'type' => $due->type->value,
            // The action/component disambiguate two cadences of one type (spark plug inspect vs
            // replace); null on a generic-default due. `rule` flags schedule-driven vs default.
            'action' => $due->action?->value,
            'component' => $due->component,
            'rule' => $due->rule,
            'lastPerformedAt' => $due->lastPerformedAt->format('Y-m-d'),
            // Time axis (null for a usage-only rule); projectedDueAt lifts the usage axis onto the
            // calendar when the history gives a usage rate.
            'dueAt' => $due->dueAt?->format('Y-m-d'),
            'projectedDueAt' => $due->projectedDueAt?->format('Y-m-d'),
            'overdue' => $due->overdue,
            'daysUntilDue' => $due->daysUntilDue,
            'intervalMonths' => $due->interval?->months,
            'usage' => $usage,
            // Generic-default advisory usage interval (null for schedule-driven, which uses `usage`).
            'usageInterval' => null === $due->interval || null === $due->interval->usageValue ? null : [
                'value' => $due->interval->usageValue,
                'unit' => $due->interval->usageUnit?->value,
            ],
        ];
    }
}
