<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\WriteOutcome;
use App\Garage\WriteOutcomeToolResult;

/**
 * Result of a log_maintenance attempt:
 *  - recorded:  `record` was appended to its vehicle's history.
 *  - ambiguous: no exact identity match, but `candidates` may be the same vehicle — the caller
 *               must reconcile (re-call with a vehicleId, or confirmNew) rather than duplicate.
 *  - notFound:  a vehicleId was given that resolves to no row.
 *
 * Mirrors App\Garage\PlanMaintenanceOutcome: both share the dedup decision via VehicleWriteResolver,
 * differing only in what they write (a record here, a planned item there).
 */
final readonly class MaintenanceLogOutcome implements WriteOutcome
{
    use WriteOutcomeToolResult;

    /**
     * @param list<Vehicle> $candidates
     */
    private function __construct(
        public string $status,
        public ?MaintenanceRecord $record = null,
        public array $candidates = [],
        public ?string $vehicleId = null,
    ) {
    }

    public static function recorded(MaintenanceRecord $record): self
    {
        return new self('recorded', record: $record);
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
