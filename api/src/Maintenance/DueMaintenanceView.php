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
        return [
            'vehicle' => $this->identity($vehicle),
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
