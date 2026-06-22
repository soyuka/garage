<?php

declare(strict_types=1);

namespace App\Entity;

use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A per-vehicle RECURRING service rule — the schedule layer that sits between the append-only
 * {@see MaintenanceRecord} history and the computed forecast. "Change the oil every 50 h (first at
 * 5 h)", "replace the paper air cartridge every 100 h or yearly". Stored, never materialised: the
 * rule lives once and {@see \App\Maintenance\DueMaintenanceCalculator} projects the next occurrence
 * from it + the latest matching record + the vehicle's current usage. This is what lets the agent
 * persist a full manufacturer schedule (e.g. Gemini's TORO 246-H answer) instead of collapsing it
 * to one log event.
 *
 * Sourced exactly like {@see VehicleFact}: a schedule IS knowledge about the vehicle, so it carries
 * provenance and a `verified` flag (catalog/SIV authoritative, a web/agent answer advisory). A
 * verified rule outranks an unverified one for the same key in the calculator's precedence, and the
 * per-vehicle rule outranks the generic {@see \App\Maintenance\MaintenanceInterval} fallback.
 *
 * Embedded on a {@see Vehicle} with the 'set' strategy keyed by {@see self::key()}, so one type can
 * hold several independent cadences (spark plug inspect@25 h vs replace@100 h; air filter foam
 * clean vs paper replace) without colliding. No identity of its own — addressed through its vehicle.
 */
#[ODM\EmbeddedDocument]
class ServiceInterval
{
    public const array AUTHORITATIVE_SOURCES = ['partsapi', 'siv'];

    // Soft-delete tombstone (declared, not promoted, for the same legacy-doc hydration reason as the
    // other embedded docs: newInstanceWithoutConstructor applies a CLASS-level default, never a
    // promoted param's). null = live.
    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        // Free-form service label (snake_case), same vocabulary as PlannedMaintenance::type — known
        // MaintenanceType values forecast + icon, the long tail is classified later, never rejected.
        #[ODM\Field(type: 'string')]
        private string $type,
        #[ODM\Field(type: 'string', enumType: MaintenanceAction::class)]
        private MaintenanceAction $action,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'bool')]
        private bool $verified = false,
        // Distinguishes two cadences of one (type, action) pair, e.g. the air filter's "foam element"
        // vs "paper cartridge". Free text; part of the dedup key.
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $component = null,
        // Usage cadence: recur every N km/mi/hours. The axis that actually matters for hour-metered
        // groundscare — "every 50 h" — which the time axis cannot express.
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $everyUsageValue = null,
        #[ODM\Field(type: 'string', enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $everyUsageUnit = null,
        // Time cadence: recur every N months ("or once a year"). When both axes are set the soonest
        // wins (whichever-first) — the calculator computes both and takes the earlier.
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $everyMonths = null,
        // Break-in override: the FIRST occurrence falls earlier than the steady cadence (the TORO's
        // first oil change at 5 h, then every 50 h). Same unit as everyUsageUnit.
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $firstUsageValue = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $url = null,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        ?\DateTimeImmutable $deletedAt = null,
    ) {
        $this->deletedAt = $deletedAt;
    }

    public static function isAuthoritativeSource(string $source): bool
    {
        return \in_array($source, self::AUTHORITATIVE_SOURCES, true);
    }

    /**
     * Build a rule from a source, deriving `verified` from it (catalog/SIV authoritative).
     */
    public static function fromSource(
        string $type,
        MaintenanceAction $action,
        string $source,
        ?string $component = null,
        ?int $everyUsageValue = null,
        ?UsageUnit $everyUsageUnit = null,
        ?int $everyMonths = null,
        ?int $firstUsageValue = null,
        ?string $url = null,
        ?string $addedBy = null,
        \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ): self {
        return new self(
            type: $type,
            action: $action,
            source: $source,
            verified: self::isAuthoritativeSource($source),
            component: $component,
            everyUsageValue: $everyUsageValue,
            everyUsageUnit: $everyUsageUnit,
            everyMonths: $everyMonths,
            firstUsageValue: $firstUsageValue,
            url: $url,
            addedAt: $addedAt,
            addedBy: $addedBy,
        );
    }

    /**
     * The dedup key for the 'set' map: type + action, plus the component when present. Two cadences
     * of the same type stay distinct (air_filter:clean:foam element vs air_filter:replace:paper).
     */
    public function key(): string
    {
        $parts = [$this->type, $this->action->value];
        if (null !== $this->component && '' !== $this->component) {
            $parts[] = $this->component;
        }

        return implode(':', $parts);
    }

    /** Human label for the schedule row: the component when it disambiguates, else the type. */
    public function label(): string
    {
        return $this->component ?? $this->type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAction(): MaintenanceAction
    {
        return $this->action;
    }

    public function getComponent(): ?string
    {
        return $this->component;
    }

    public function getEveryUsageValue(): ?int
    {
        return $this->everyUsageValue;
    }

    public function getEveryUsageUnit(): ?UsageUnit
    {
        return $this->everyUsageUnit;
    }

    public function getEveryMonths(): ?int
    {
        return $this->everyMonths;
    }

    public function getFirstUsageValue(): ?int
    {
        return $this->firstUsageValue;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    /** Tombstone the rule: it leaves the forecast but its document survives (recoverable). */
    public function delete(\DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->deletedAt = $at;
    }
}
