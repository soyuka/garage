<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * A forecast service for one (vehicle, rule): when it was last done and when it is next due. The
 * forecast has up to two axes, reported together (never collapsed to one winner) so the host can
 * say "due 2027-05-15 OR in 30 engine-hours, whichever first":
 *
 *  - TIME — {@see $dueAt}/{@see $daysUntilDue}: lastPerformedAt + the rule's month interval.
 *  - USAGE — {@see $dueUsageValue}/{@see $usageRemaining}: the reading at the last service + the
 *    rule's usage cadence, measured against the vehicle's current reading. The axis that actually
 *    matters for hour-metered groundscare, which the time axis cannot express.
 *
 * {@see $projectedDueAt} lifts the usage axis onto the calendar when the history yields a usage rate.
 * {@see $overdue} is true when EITHER axis is breached. Every due is schedule-driven — it carries its
 * {@see $action}/{@see $component} from the per-vehicle {@see \App\Entity\ServiceInterval} rule.
 */
final readonly class DueMaintenance
{
    public function __construct(
        public MaintenanceType $type,
        public \DateTimeImmutable $lastPerformedAt,
        public ?\DateTimeImmutable $dueAt,
        public bool $overdue,
        public ?int $daysUntilDue,
        public ?MaintenanceAction $action = null,
        public ?string $component = null,
        public ?int $lastUsageValue = null,
        public ?int $dueUsageValue = null,
        public ?int $usageRemaining = null,
        public ?UsageUnit $usageUnit = null,
        public ?\DateTimeImmutable $projectedDueAt = null,
        // Provenance of the rule this due came from — always 'schedule' (a per-vehicle ServiceInterval)
        // now that the generic fallback is gone. Kept on the wire for forward-compatibility.
        public string $rule = 'schedule',
    ) {
    }

    /** The date the forecast sorts/filters on: the time axis, else the usage-rate projection. */
    public function effectiveDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt ?? $this->projectedDueAt;
    }
}
