<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;

/**
 * Result of an update_vehicle attempt:
 *  - written:   the provided scalar fields were patched onto `vehicle`.
 *  - ambiguous: no exact identity match, but `candidates` are stored vehicles that may be the
 *               same one — the caller must reconcile (re-call with a vehicleId, or confirmNew)
 *               rather than spawn a duplicate row.
 *  - notFound:  a vehicleId was given that resolves to no row.
 *
 * No "refused" status: patching a vehicle's own columns carries no fact-precedence rule.
 */
final readonly class UpdateVehicleOutcome
{
    /**
     * @param list<Vehicle> $candidates
     */
    private function __construct(
        public string $status,
        public ?Vehicle $vehicle = null,
        public array $candidates = [],
        public ?string $vehicleId = null,
    ) {
    }

    public static function written(Vehicle $vehicle): self
    {
        return new self('written', vehicle: $vehicle);
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
