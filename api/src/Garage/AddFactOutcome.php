<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use App\Entity\VehicleFact;

/**
 * Result of an add_vehicle_fact attempt:
 *  - written:   the fact was inserted/updated on its vehicle.
 *  - refused:   `fact` is an existing verified authoritative fact an llm/user value must
 *               never silently overwrite (precedence rule); it is left untouched.
 *  - ambiguous: no exact identity match, but `candidates` are stored vehicles that may be the
 *               same one — the caller must reconcile (re-call with a vehicleId, or confirmNew)
 *               rather than spawn a duplicate row.
 *  - notFound:  a vehicleId was given that resolves to no row.
 */
final readonly class AddFactOutcome
{
    /**
     * @param list<Vehicle> $candidates
     */
    private function __construct(
        public string $status,
        public ?VehicleFact $fact = null,
        public array $candidates = [],
        public ?int $vehicleId = null,
    ) {
    }

    public static function written(VehicleFact $fact): self
    {
        return new self('written', fact: $fact);
    }

    public static function refused(VehicleFact $existing): self
    {
        return new self('refused', fact: $existing);
    }

    /**
     * @param list<Vehicle> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self('ambiguous', candidates: $candidates);
    }

    public static function notFound(int $vehicleId): self
    {
        return new self('not_found', vehicleId: $vehicleId);
    }
}
