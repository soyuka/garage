<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\UsageUnit;
use App\Maintenance\PlannedMaintenanceStatus;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A maintenance item — the single store for everything the agent knows about a service. Two kinds:
 *  - ACTIONABLE = a to-do on the checklist (carries parts, and/or a target/dueDate it is pegged to);
 *  - KNOWN      = a pure spec carrier (ONLY attributes, no parts/target/date) — off the checklist
 *                 (e.g. the catalog spec sheet, or a fact the user simply stated), surfaced only
 *                 through the facts projection. See {@see self::isSpecCarrier()}.
 * Both carry `attributes` — embedded {@see VehicleFact} specs keyed by fact key. The vehicle has NO
 * fact store of its own: facts are projected from items by {@see \App\Maintenance\VehicleFactsProjector}.
 *
 * Siblings on a Vehicle: MaintenanceRecord = an event that HAPPENED (append-only history);
 * PlannedMaintenance = an open intent that resolves to a record once performed.
 *
 * Dedup is logical (one open task per vehicle+type+label, enforced in the planner), NOT a DB unique
 * constraint — a done task and a fresh open task of the same type must coexist. `owner` is the same
 * multi-tenancy seam as Vehicle: null = shared/global in phase 1.
 *
 * @see \App\Maintenance\PlannedMaintenancePlanner
 */
#[ODM\Document(collection: 'planned_maintenance', repositoryClass: PlannedMaintenanceRepository::class)]
#[ODM\Index(keys: ['vehicle' => 'asc', 'status' => 'asc'], name: 'idx_planned_vehicle_status')]
class PlannedMaintenance
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    // The item's specs, embedded as a `{key: {...}}` map (the 'set' strategy keys the BSON object by
    // the collection's PHP keys), so one-attribute-per-key is structural. These are the facts the
    // projection reads — the item is the only place a spec is stored.
    /** @var Collection<string, VehicleFact> */
    #[ODM\EmbedMany(targetDocument: VehicleFact::class, strategy: 'set')]
    private Collection $attributes;

    public function __construct(
        #[ODM\ReferenceOne(targetDocument: Vehicle::class, storeAs: 'id', inversedBy: 'plannedTasks')]
        private Vehicle $vehicle,
        // Free-form service label (snake_case), e.g. oil_change, timing_belt, gearbox_oil. NOT a
        // closed enum: the agent names a service for what it is instead of collapsing the long tail to
        // "other". Forecasting/icons key off the known MaintenanceType values (MaintenanceType::tryFrom);
        // an unknown type simply gets no interval — classified later, never rejected.
        #[ODM\Field(type: 'string')]
        private string $type,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'string', enumType: PlannedMaintenanceStatus::class)]
        private PlannedMaintenanceStatus $status = PlannedMaintenanceStatus::Open,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $label = null,
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $targetUsageValue = null,
        #[ODM\Field(type: 'string', enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $targetUsageUnit = null,
        #[ODM\Field(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $dueDate = null,
        /** @var list<array{ref: string, label?: string, brand?: string, qty?: int}> */
        #[ODM\Field(type: 'collection')]
        private array $parts = [],
        #[ODM\Field(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $completedAt = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $owner = null,
    ) {
        $this->id = (string) new Ulid();
        $this->attributes = new ArrayCollection();
        $vehicle->addPlannedTask($this);
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): PlannedMaintenanceStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return PlannedMaintenanceStatus::Open === $this->status;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getTargetUsageValue(): ?int
    {
        return $this->targetUsageValue;
    }

    public function getTargetUsageUnit(): ?UsageUnit
    {
        return $this->targetUsageUnit;
    }

    public function setTarget(?int $value, ?UsageUnit $unit): void
    {
        $this->targetUsageValue = $value;
        $this->targetUsageUnit = $unit;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): void
    {
        $this->dueDate = $dueDate;
    }

    /**
     * @return list<array{ref: string, label?: string, brand?: string, qty?: int}>
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * @param list<array{ref: string, label?: string, brand?: string, qty?: int}> $parts
     */
    public function setParts(array $parts): void
    {
        $this->parts = $parts;
    }

    /**
     * Additively upsert parts by `ref` — incoming parts replace a same-ref entry in place, new refs
     * append, unmentioned ones stay. Symmetric with {@see self::setAttribute()}: an incremental plan
     * ("ajoute l'huile à la liste") must add to the shopping list, never clobber the parts a prior
     * pass resolved (e.g. the oil filter when only the oil is re-sent).
     *
     * @param list<array{ref: string, label?: string, brand?: string, qty?: int}> $parts
     */
    public function mergeParts(array $parts): void
    {
        $byRef = [];
        foreach ([...$this->parts, ...$parts] as $part) {
            $byRef[$part['ref']] = $part;
        }

        $this->parts = array_values($byRef);
    }

    /**
     * @return Collection<string, VehicleFact>
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function attributeByKey(string $key): ?VehicleFact
    {
        return $this->attributes->get($key);
    }

    /**
     * Upsert a spec, keyed by its fact key — re-setting a key replaces it in place rather than
     * stacking a duplicate the keyed map would shadow.
     */
    public function setAttribute(VehicleFact $attribute): void
    {
        $this->attributes->set($attribute->getKey(), $attribute);
    }

    /**
     * Whether this item belongs on the to-do checklist — true unless it is a pure spec carrier.
     */
    public function isActionable(): bool
    {
        return !$this->isSpecCarrier();
    }

    /**
     * A KNOWN spec carrier: it ONLY carries attributes (specs) — no parts, no target, no due date.
     * The catalog spec sheet and a bare user-stated fact are spec carriers; they feed the facts
     * projection but never show on the checklist. An item with parts, a target or a date is a real
     * task even when it also carries specs.
     */
    public function isSpecCarrier(): bool
    {
        return !$this->attributes->isEmpty()
            && [] === $this->parts
            && null === $this->targetUsageValue
            && null === $this->dueDate;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    /**
     * Mark the task resolved. `completedAt` is only stamped when the work was actually done
     * (Done), not when it is dismissed.
     */
    public function close(PlannedMaintenanceStatus $status, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->completedAt = PlannedMaintenanceStatus::Done === $status ? $at : null;
    }

    public function reopen(): void
    {
        $this->status = PlannedMaintenanceStatus::Open;
        $this->completedAt = null;
    }
}
