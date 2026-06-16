<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * The interval-derived forecast for one vehicle. `unknownBaseline` is the deliberate other half
 * of the answer: recurring services we have NO logged history for, so we honestly cannot predict
 * them — the caller surfaces them ("when was this last done? log it") rather than pretending the
 * vehicle is fully up to date.
 */
final readonly class VehicleMaintenanceForecast
{
    /**
     * @param list<DueMaintenance>  $due             forecast services, soonest due first
     * @param list<MaintenanceType> $unknownBaseline recurring types with no record to forecast from
     */
    public function __construct(
        public array $due,
        public array $unknownBaseline,
    ) {
    }
}
