<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;

/**
 * One GENERIC service cadence from the reviewed catalogue ({@see ServiceDefaultsCatalog}) — advisory
 * reference data, NOT a vehicle's rule. It is never stored on a vehicle, never feeds the forecast:
 * applying one stays an explicit act through set_maintenance_schedule, and a stored
 * {@see \App\Entity\ServiceInterval} always outranks it.
 *
 * The cadence is a function of a DISCRIMINANT, not of the service: a timing BELT has a 120 000 km
 * interval while a CHAIN has none at all, an iridium plug lasts ~2.5× a nickel one. So a baseline
 * declares the fact key it hangs off ({@see $discriminantKey}) and the values it covers
 * ({@see $discriminantValues}); the resolver refuses to pick between two baselines whose
 * discriminant is unknown on the vehicle.
 *
 * NO cadence at all is a legitimate, load-bearing entry: "a timing chain has no replacement
 * interval" and "a petrol in-tank fuel filter is a lifetime part" are the ANSWER, not a hole in
 * the data. {@see hasCadence()} tells the two apart from a merely empty row.
 */
final readonly class ServiceBaseline
{
    /**
     * @param list<VehicleType> $segments           vehicle segments this cadence is valid for
     * @param string            $reviewedOn         ISO-8601 date (YYYY-MM-DD) the entry was last checked
     *                                              against its source — a plain string because it is
     *                                              provenance echoed verbatim, never date arithmetic
     * @param list<string>      $discriminantValues lowercase tokens of {@see $discriminantKey} covered
     */
    public function __construct(
        public string $type,
        public MaintenanceAction $action,
        public array $segments,
        public string $source,
        public string $reviewedOn,
        public ?string $discriminantKey = null,
        public array $discriminantValues = [],
        public ?int $everyUsageValue = null,
        public ?UsageUnit $everyUsageUnit = null,
        public ?int $everyMonths = null,
        public ?string $sourceUrl = null,
        public ?string $note = null,
    ) {
        // Fail at load, not inside a tool call: these two pairs are meaningless half-set, and a
        // typo'd entry must break every test run rather than reach the model as a -32603.
        if ((null === $everyUsageValue) !== (null === $everyUsageUnit)) {
            throw new \InvalidArgumentException(sprintf('Baseline "%s": a usage cadence needs both a value and a unit.', $type));
        }
        if ((null === $discriminantKey) !== ([] === $discriminantValues)) {
            throw new \InvalidArgumentException(sprintf('Baseline "%s": a discriminant key needs the values it applies to.', $type));
        }
    }

    /**
     * A stable handle for the entry: type + action, plus the discriminant it is scoped to. Mirrors
     * {@see \App\Entity\ServiceInterval::key()} so a baseline and the rule it would become read alike.
     */
    public function key(): string
    {
        $parts = [$this->type, $this->action->value];
        if ([] !== $this->discriminantValues) {
            $parts[] = implode('|', $this->discriminantValues);
        }

        return implode(':', $parts);
    }

    /** False is an ANSWER ("no replacement interval"), not a missing value — see the class docblock. */
    public function hasCadence(): bool
    {
        return null !== $this->everyUsageValue || null !== $this->everyMonths;
    }

    public function appliesTo(VehicleType $segment): bool
    {
        return \in_array($segment, $this->segments, true);
    }

    /**
     * Does a vehicle's recorded fact value select this baseline? Matched on whole words, folded to
     * lowercase: a fact is written by a human or an LLM ("timing chain", "Iridium NGK", "Essence"),
     * never as the bare token, so an equality test would leave every real vehicle ambiguous.
     */
    public function matchesDiscriminant(string $factValue): bool
    {
        $folded = mb_strtolower(trim($factValue));
        foreach ($this->discriminantValues as $value) {
            if (1 === preg_match('/\b'.preg_quote($value, '/').'\b/u', $folded)) {
                return true;
            }
        }

        return false;
    }
}
