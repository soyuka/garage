<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\VehicleFact;

/**
 * Derives the fluid line of a fluid-service planned task ("what oil, what quantity") from the
 * vehicle's projected facts, at READ time. The stored part schema is left as {ref,label,brand,qty}:
 * the bare "Huile moteur ×5" shopping line — which says neither which oil nor the fill volume — gets
 * a derived, read-only `spec` sub-object {viscosity, standard, capacity, unit, verified} pulled from
 * the {prefix}_viscosity / {prefix}_type / {prefix}_capacity facts. Facts stay the single source of
 * truth; nothing is duplicated into storage.
 *
 * Only a ref-LESS part is a fluid (a cataloged part carries a part number); only the first such part
 * of the task is enriched (an oil_change has one engine-oil line). Verified reflects the standard
 * (the manufacturer spec) — a catalog ACEA grade is authoritative, a web viscosity is not.
 */
final class PlannedTaskFluidEnricher
{
    /** Known service types whose fluid line maps to a differently-named fact-key prefix. */
    private const array PREFIX_BY_TYPE = [
        'oil_change' => 'oil',
        'coolant' => 'coolant',
    ];

    /**
     * @param list<array<string, mixed>> $parts the task's stored parts
     * @param list<VehicleFact>          $facts the vehicle's projected facts
     *
     * @return list<array<string, mixed>> the parts, with `spec` set on the first fluid part when derivable
     */
    public function enrich(string $type, array $parts, array $facts): array
    {
        $byKey = [];
        foreach ($facts as $fact) {
            $byKey[$fact->getKey()] = $fact;
        }

        $prefix = self::prefixFor($type, $byKey);
        if (null === $prefix) {
            return $parts;
        }

        $spec = self::specFrom($prefix, $byKey);
        if (null === $spec) {
            return $parts;
        }

        $applied = false;
        foreach ($parts as &$part) {
            if (!$applied && self::isFluid($part)) {
                $part['spec'] = $spec;
                $applied = true;
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $part
     */
    private static function isFluid(array $part): bool
    {
        return '' === trim((string) ($part['ref'] ?? ''));
    }

    /**
     * @param array<string, VehicleFact> $byKey
     */
    private static function prefixFor(string $type, array $byKey): ?string
    {
        if (isset(self::PREFIX_BY_TYPE[$type])) {
            return self::PREFIX_BY_TYPE[$type];
        }

        // Free-text fluid types (gearbox_oil, brake_fluid, power_steering_oil) carry their own
        // {type}_capacity/_type facts — use the type itself as the prefix when one is present.
        if (isset($byKey[$type.'_capacity']) || isset($byKey[$type.'_type'])) {
            return $type;
        }

        return null;
    }

    /**
     * @param array<string, VehicleFact> $byKey
     *
     * @return array{verified: bool, viscosity?: string, standard?: string, capacity?: string, unit?: string}|null
     */
    private static function specFrom(string $prefix, array $byKey): ?array
    {
        $viscosity = $byKey[$prefix.'_viscosity'] ?? null;
        $standard = $byKey[$prefix.'_type'] ?? null;
        $capacity = $byKey[$prefix.'_capacity'] ?? null;

        if (null === $viscosity && null === $standard && null === $capacity) {
            return null;
        }

        $spec = ['verified' => null !== $standard && $standard->isVerified()];
        if (null !== $viscosity) {
            $spec['viscosity'] = $viscosity->getValue();
        }
        if (null !== $standard) {
            $spec['standard'] = $standard->getValue();
        }
        if (null !== $capacity) {
            $spec['capacity'] = $capacity->getValue();
            if (null !== $capacity->getUnit()) {
                $spec['unit'] = $capacity->getUnit();
            }
        }

        return $spec;
    }
}
