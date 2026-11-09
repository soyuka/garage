<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use Symfony\Component\Uid\Ulid;

/**
 * Outcome of resolving which vehicle a write should land on, without spawning a duplicate:
 *  - resolved:  `vehicle` is the row to write to (matched, addressed by id, or freshly created).
 *  - ambiguous: no exact match, but `candidates` may be the same vehicle — the caller must
 *               reconcile (re-call with a vehicleId, or confirmNew) instead of creating.
 *  - notFound:  a vehicleId was given that resolves to no row.
 *
 * The single dedup brain shared by plan_maintenance and log_maintenance, so both write paths
 * make the same reconcile-or-create decision. See {@see VehicleWriteResolver}.
 */
final readonly class VehicleResolution
{
    /**
     * @param list<Vehicle> $candidates
     */
    private function __construct(
        public string $status,
        public ?Vehicle $vehicle = null,
        public array $candidates = [],
        public ?string $vehicleId = null,
        public bool $created = false,
    ) {
    }

    public static function resolved(Vehicle $vehicle, bool $created = false): self
    {
        return new self('resolved', vehicle: $vehicle, created: $created);
    }

    /**
     * @param list<Vehicle> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self('ambiguous', candidates: $candidates);
    }

    /**
     * The vehicle of a `resolved` resolution; the caller has already returned on `ambiguous` and `not_found`.
     */
    public function resolvedVehicle(): Vehicle
    {
        return $this->vehicle ?? throw new \LogicException(\sprintf('A "%s" vehicle resolution names no vehicle.', $this->status));
    }

    public static function notFound(Ulid $vehicleId): self
    {
        return new self('not_found', vehicleId: (string) $vehicleId);
    }
}
