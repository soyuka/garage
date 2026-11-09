<?php

declare(strict_types=1);

namespace App\Maintenance;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Garage\ResourceIri;

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
     * @return array{'@id': string, make: string, model: string, year: int|null, vehicleType: string}
     */
    public function identity(Vehicle $vehicle): array
    {
        return [
            '@id' => ResourceIri::of($this->iriConverter, GarageVehicle::fromEntity($vehicle)),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
        ];
    }

    /**
     * The garage-wide due row: the bare forecast item plus the vehicle identity (the listing spans
     * vehicles). The per-vehicle forecast sub-resource uses {@see self::item()} without the identity.
     *
     * @return array<string, mixed>
     */
    public function dueItem(Vehicle $vehicle, DueMaintenance $due): array
    {
        return ['vehicle' => $this->identity($vehicle), ...$this->item($due)];
    }

    /**
     * A compact one-line summary of a single due item — what the parc listing carries per vehicle so
     * the sidebar rail shows the soonest service without a forecast fetch.
     *
     * @return array{type: string, action: string|null, label: string, rule: string, overdue: bool, dueAt: string|null, projectedDueAt: string|null, daysUntilDue: int|null, usageRemaining: int|null, usageUnit: string|null}
     */
    public function compact(DueMaintenance $due): array
    {
        return [
            'type' => $due->type->value,
            'action' => $due->action?->value,
            'label' => $due->component ?? $due->type->value,
            'rule' => $due->rule,
            'overdue' => $due->overdue,
            'dueAt' => $due->dueAt?->format('Y-m-d'),
            'projectedDueAt' => $due->projectedDueAt?->format('Y-m-d'),
            'daysUntilDue' => $due->daysUntilDue,
            'usageRemaining' => $due->usageRemaining,
            'usageUnit' => $due->usageUnit?->value,
        ];
    }

    /**
     * One forecast item, vehicle-agnostic — both axes (time + usage), the action/component, and the
     * rule provenance.
     *
     * @return array<string, mixed>
     */
    public function item(DueMaintenance $due): array
    {
        // The usage axis (engine-hours/km): what the schedule-driven forecast actually measures.
        $usage = null === $due->usageUnit || null === $due->dueUsageValue ? null : [
            'last' => $due->lastUsageValue,
            'due' => $due->dueUsageValue,
            'remaining' => $due->usageRemaining,
            'unit' => $due->usageUnit->value,
        ];

        return [
            'type' => $due->type->value,
            // The action/component disambiguate two cadences of one type (spark plug inspect vs replace).
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
            'usage' => $usage,
        ];
    }
}
