<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\ServiceInterval;
use App\Entity\Vehicle;

/**
 * Result of a set_maintenance_schedule attempt:
 *  - set:       `vehicle` is the row the recurring rules were upserted onto, `intervals` the live
 *               schedule after the write.
 *  - ambiguous: no exact identity match, but `candidates` may be the same vehicle (reconcile/confirmNew).
 *  - notFound:  a vehicleId was given that resolves to no row.
 *
 * Mirrors {@see PlanMaintenanceOutcome}.
 */
final readonly class SetScheduleOutcome implements WriteOutcome
{
    use WriteOutcomeToolResult;

    /**
     * @param list<Vehicle>         $candidates
     * @param list<ServiceInterval> $intervals
     */
    private function __construct(
        public string $status,
        public ?Vehicle $vehicle = null,
        public array $intervals = [],
        public array $candidates = [],
        public ?string $vehicleId = null,
    ) {
    }

    /**
     * @param list<ServiceInterval> $intervals
     */
    public static function set(Vehicle $vehicle, array $intervals): self
    {
        return new self('set', vehicle: $vehicle, intervals: $intervals);
    }

    /**
     * The vehicle of the success outcome; the caller has already handled every other status.
     */
    public function scheduledVehicle(): Vehicle
    {
        return $this->vehicle ?? throw new \LogicException(\sprintf('A "%s" outcome carries no vehicle.', $this->status));
    }

    /**
     * @param list<Vehicle> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self('ambiguous', candidates: $candidates);
    }

    public static function notFound(?string $vehicleId): self
    {
        return new self('not_found', vehicleId: $vehicleId);
    }
}
