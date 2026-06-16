<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\Vehicle;

/**
 * Flattens the forecast into the arrays the list_due_maintenance tool surfaces. A due item
 * carries its vehicle identity (the listing is garage-wide), the time-based due date, and the
 * usage interval as advisory data so the host can ask the user for a current reading.
 */
final class DueMaintenanceView
{
    /**
     * @return array{make: string, model: string, year: int|null, vehicleType: string}
     */
    public static function identity(Vehicle $vehicle): array
    {
        return [
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dueItem(Vehicle $vehicle, DueMaintenance $due): array
    {
        return [
            'vehicle' => self::identity($vehicle),
            'type' => $due->type->value,
            'lastPerformedAt' => $due->lastPerformedAt->format('Y-m-d'),
            'dueAt' => $due->dueAt->format('Y-m-d'),
            'overdue' => $due->overdue,
            'daysUntilDue' => $due->daysUntilDue,
            'intervalMonths' => $due->interval->months,
            'usageInterval' => null === $due->interval->usageValue ? null : [
                'value' => $due->interval->usageValue,
                'unit' => $due->interval->usageUnit?->value,
            ],
        ];
    }
}
