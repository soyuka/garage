<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

use App\Entity\Vehicle;
use App\Maintenance\MaintenanceTypeNormalizer;
use App\Maintenance\VehicleFactsProjector;

/**
 * Answers "what is the generic interval for this service?" from the reviewed
 * {@see ServiceDefaultsCatalog}, against a vehicle when one is given.
 *
 * The contract that makes this safe: it returns a number ONLY when a single baseline applies. When
 * several do and the discriminating fact is unknown, it returns Ambiguous WITH the fact key that
 * would settle it — the caller asks the user or looks the fact up. Averaging the candidates, taking
 * the first, or falling back to the most common one would reproduce the bug this exists to prevent
 * (a belt interval written onto a chain-driven engine).
 *
 * It reads the discriminant through {@see VehicleFactsProjector}, the same seam get_vehicle_facts
 * uses — there is no fact store of its own. It never writes: nothing here reaches a vehicle's
 * serviceSchedule or the forecast.
 */
final readonly class ServiceDefaultsResolver
{
    public function __construct(
        private ServiceDefaultsCatalog $catalog,
        private VehicleFactsProjector $facts,
        private MaintenanceTypeNormalizer $normalizer,
    ) {
    }

    public function resolve(string $type, ?Vehicle $vehicle = null): ServiceDefaultsResolution
    {
        $canonical = $this->normalizer->canonical($type);
        $candidates = $this->catalog->forType($canonical);

        if (null !== $vehicle) {
            $segment = $vehicle->getVehicleType();
            $candidates = array_values(array_filter(
                $candidates,
                static fn (ServiceBaseline $b): bool => $b->appliesTo($segment),
            ));
        }

        if ([] === $candidates) {
            return ServiceDefaultsResolution::unknown(
                $canonical,
                \sprintf('No reviewed baseline covers "%s"%s. Say so plainly, or look the interval up for this exact vehicle and store it with set_maintenance_schedule.', $canonical, null !== $vehicle ? ' for a '.$vehicle->getVehicleType()->value : ''),
            );
        }

        $key = $this->singleDiscriminantKey($candidates);
        if (null === $key) {
            if (1 === \count($candidates)) {
                return ServiceDefaultsResolution::resolved($canonical, $candidates[0]);
            }

            // No fact can settle this one: the candidates are scoped to different vehicle segments
            // (a mower's engine-hour cadence vs a car's distance cadence), so only a vehicle can.
            return ServiceDefaultsResolution::ambiguous(
                $canonical,
                $candidates,
                null,
                \sprintf('Several baselines apply, scoped to different vehicle segments (%s). Pass the vehicle @id so the right segment is used — do NOT pick one.', implode(', ', $this->segmentsOf($candidates))),
            );
        }

        $value = null !== $vehicle ? $this->factValue($vehicle, $key) : null;
        if (null === $value) {
            return ServiceDefaultsResolution::ambiguous(
                $canonical,
                $candidates,
                $key,
                \sprintf('The interval depends on `%s`, which is unknown on this vehicle. Ask the user or look that fact up first — never pick one of these numbers.', $key),
            );
        }

        $matching = array_values(array_filter(
            $candidates,
            static fn (ServiceBaseline $b): bool => $b->matchesDiscriminant($value),
        ));

        if (1 === \count($matching)) {
            return ServiceDefaultsResolution::resolved($canonical, $matching[0], $key, $value);
        }

        return ServiceDefaultsResolution::ambiguous(
            $canonical,
            $candidates,
            $key,
            \sprintf('`%s` is recorded as "%s" on this vehicle, which matches %s of the baselines below. Confirm the value before quoting an interval.', $key, $value, [] === $matching ? 'none' : 'several'),
        );
    }

    /**
     * The one fact key the candidates hang off, or null when they carry none — or several different
     * ones, which no single fact could settle either.
     *
     * @param list<ServiceBaseline> $candidates
     */
    private function singleDiscriminantKey(array $candidates): ?string
    {
        $keys = array_values(array_unique(array_filter(
            array_map(static fn (ServiceBaseline $b): ?string => $b->discriminantKey, $candidates),
        )));

        return 1 === \count($keys) ? $keys[0] : null;
    }

    /**
     * @param list<ServiceBaseline> $candidates
     *
     * @return list<string>
     */
    private function segmentsOf(array $candidates): array
    {
        $segments = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate->segments as $segment) {
                $segments[$segment->value] = true;
            }
        }

        return array_keys($segments);
    }

    private function factValue(Vehicle $vehicle, string $key): ?string
    {
        foreach ($this->facts->forVehicle($vehicle) as $fact) {
            if ($fact->getKey() === $key) {
                return $fact->getValue();
            }
        }

        return null;
    }
}
