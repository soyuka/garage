<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\VehicleFact;

/**
 * Result of an add_vehicle_fact attempt. When `refused` is true the input was rejected
 * because `fact` is an existing verified authoritative fact that an LLM/user value must
 * never silently overwrite (see docs/TODO.md precedence rule); the fact is left untouched.
 */
final readonly class AddFactOutcome
{
    private function __construct(
        public bool $refused,
        public VehicleFact $fact,
    ) {
    }

    public static function written(VehicleFact $fact): self
    {
        return new self(false, $fact);
    }

    public static function refused(VehicleFact $existing): self
    {
        return new self(true, $existing);
    }
}
