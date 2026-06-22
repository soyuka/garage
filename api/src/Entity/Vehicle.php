<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A vehicle held in the garage (the self-strengthening store), distinct from the
 * SIV-sourced API representation App\ApiResource\Vehicle.
 *
 * Phase 1 is a single shared garage: `owner` is the multi-tenancy seam — null means
 * shared/global. Per-user isolation later is a backfill + non-null query change, not a rewrite.
 */
// Identity lookups (findByIdentity/findShared/findCandidates) match make+model case-insensitively
// within an owner. The collation pushes that case-folding into the index, so the queries use plain
// equality instead of a `^…$/i` regex (the `i` flag defeats index use). Every read narrows by owner
// first — the tenancy seam — so owner leads each index; the same indexes serve the per-user phase.
#[ODM\Document(collection: 'vehicles', repositoryClass: VehicleRepository::class)]
#[ODM\Index(keys: ['owner' => 'asc', 'vehicleType' => 'asc', 'make' => 'asc', 'model' => 'asc', 'year' => 'asc'], name: 'idx_vehicle_identity', options: ['collation' => Vehicle::IDENTITY_COLLATION])]
#[ODM\Index(keys: ['owner' => 'asc', 'addedAt' => 'desc'], name: 'idx_vehicle_owner_added')]
#[ODM\Index(keys: ['owner' => 'asc', 'updatedAt' => 'desc'], name: 'idx_vehicle_owner_updated')]
class Vehicle
{
    // strength 2 = case-insensitive, accent-sensitive — preserves the old `/i`-regex semantics
    // (case folded, accents kept). The queries must request the SAME collation or the index is skipped.
    public const array IDENTITY_COLLATION = ['locale' => 'fr', 'strength' => 2];

    // App-assigned ULID public identifier — the dereferenceable IRI key (/garage/vehicles/{id}),
    // so no internal handle is ever exposed. Stored as its base32 string (the Mongo _id);
    // getId() rehydrates the Ulid. Time-ordered, so it stays index-friendly.
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    /** @var Collection<int, MaintenanceRecord> */
    #[ODM\ReferenceMany(targetDocument: MaintenanceRecord::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true, storeAs: 'id')]
    private Collection $maintenanceRecords;

    /** @var Collection<int, PlannedMaintenance> */
    #[ODM\ReferenceMany(targetDocument: PlannedMaintenance::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true, storeAs: 'id')]
    private Collection $plannedTasks;

    // The per-vehicle recurring SCHEDULE — embedded {@see ServiceInterval} rules keyed by
    // {@see ServiceInterval::key()} (the 'set' strategy stores them as a `{key: {...}}` map, so one
    // rule per key is structural while one type still holds several cadences via distinct keys).
    // Distinct from plannedTasks (open to-dos that resolve to records) and maintenanceRecords (the
    // history): this is the rule the forecast projects the next occurrence from.
    /** @var Collection<string, ServiceInterval> */
    #[ODM\EmbedMany(targetDocument: ServiceInterval::class, strategy: 'set')]
    private Collection $serviceSchedule;

    // Manufacturer engine code (SIV code_moteur, e.g. "WJY", "F1AE0481B") or a plain displacement
    // label the user keys in ("1.9"). Distinct from the kType (TecDoc id) and the modification
    // (trim label): the motor a mechanic asks for. Not on the dedup path — purely descriptive.
    //
    // Declared (not constructor-promoted) on purpose: ODM hydrates legacy docs via
    // newInstanceWithoutConstructor, which applies CLASS-level defaults but never a promoted
    // param's default. Docs persisted before this field carry no `engineCode` key, so a promoted
    // property would stay uninitialized and the getter would fatal. The `= null` default here makes
    // the read tolerate that schema drift.
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $engineCode = null;

    // Soft-delete tombstone. A deleted vehicle stays in the collection (its maintenance history is
    // never erased) but every read path — the parc listing, identity dedup, the item GET — skips
    // it. null = live. See {@see self::delete()}. Declared (not promoted) for the same legacy-doc
    // hydration reason as $engineCode above.
    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    // Last write to this vehicle (a maintenance log/plan, or an update_vehicle patch). Surfaced so the
    // parc listing and list_vehicles can order most-recently-touched first — the server-side echo of
    // the dashboard's client-side recency rail. Declared (not promoted) with a null default for the
    // same legacy-doc hydration reason as $deletedAt: docs persisted before this field carry no
    // `updatedAt` key, so the read must tolerate its absence (it sorts last until first touched).
    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Suppression tombstones for PROJECTED facts that have no document of their own — the ref facts
     * {@see \App\Maintenance\VehicleFactsProjector} derives from logged parts. Maps a fact key to the
     * instant the user removed it from the card; the projector hides that key while its source visit
     * is no newer than this stamp, so a later fitment (a fresh visit) re-surfaces it as new evidence.
     * Attribute-backed facts are tombstoned on their own document instead (see VehicleFact::delete()).
     *
     * @var array<string, string> key => ISO-8601 suppression instant
     */
    // Declared (not promoted) with a `= []` default for the same legacy-doc hydration reason as the
    // fields above: docs persisted before this field carry no `suppressedFactKeys` key.
    #[ODM\Field(type: 'hash')]
    private array $suppressedFactKeys = [];

    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $make,
        #[ODM\Field(type: 'string')]
        private string $model,
        #[ODM\Field(type: 'string', enumType: VehicleType::class)]
        private VehicleType $vehicleType,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $year = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $modification = null,
        ?string $engineCode = null,
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $kType = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $vin = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $plate = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $owner = null,
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $usageValue = null,
        #[ODM\Field(type: 'string', nullable: true, enumType: UsageUnit::class)]
        private ?UsageUnit $usageUnit = null,
        ?\DateTimeImmutable $deletedAt = null,
    ) {
        $this->id = (string) new Ulid();
        $this->maintenanceRecords = new ArrayCollection();
        $this->plannedTasks = new ArrayCollection();
        $this->serviceSchedule = new ArrayCollection();
        $this->engineCode = $engineCode;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
    }

    public function getMake(): string
    {
        return $this->make;
    }

    public function setMake(string $make): void
    {
        $this->make = $make;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): void
    {
        $this->year = $year;
    }

    public function getModification(): ?string
    {
        return $this->modification;
    }

    public function setModification(?string $modification): void
    {
        $this->modification = $modification;
    }

    public function getEngineCode(): ?string
    {
        return $this->engineCode;
    }

    public function setEngineCode(?string $engineCode): void
    {
        $this->engineCode = $engineCode;
    }

    public function getKType(): ?int
    {
        return $this->kType;
    }

    public function setKType(?int $kType): void
    {
        $this->kType = $kType;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function setVin(?string $vin): void
    {
        $this->vin = $vin;
    }

    public function getPlate(): ?string
    {
        return $this->plate;
    }

    public function setPlate(?string $plate): void
    {
        $this->plate = $plate;
    }

    public function getVehicleType(): VehicleType
    {
        return $this->vehicleType;
    }

    public function setVehicleType(VehicleType $vehicleType): void
    {
        $this->vehicleType = $vehicleType;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(?string $owner): void
    {
        $this->owner = $owner;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Stamp the vehicle as just modified — drives the most-recently-touched ordering of the parc. */
    public function touch(\DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->updatedAt = $at;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    /** Tombstone the vehicle: it leaves every read path but its document (and history) survives. */
    public function delete(\DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->deletedAt = $at;
    }

    public function getUsageValue(): ?int
    {
        return $this->usageValue;
    }

    public function getUsageUnit(): ?UsageUnit
    {
        return $this->usageUnit;
    }

    public function setUsage(int $value, UsageUnit $unit): void
    {
        $this->usageValue = $value;
        $this->usageUnit = $unit;
    }

    /**
     * Adopt an observed usage reading (e.g. the odometer noted on a maintenance log) while keeping
     * the stored value monotonic: taken when none is stored yet, or when it advances a same-unit
     * reading — odometers and engine-hours never run backwards. A reading in a different unit is
     * ignored; switching the unit is a deliberate {@see setUsage} via update_vehicle, not a stray
     * service entry. Returns whether the reading was adopted.
     */
    public function recordUsageReading(int $value, UsageUnit $unit): bool
    {
        if (null !== $this->usageValue && ($this->usageUnit !== $unit || $value <= $this->usageValue)) {
            return false;
        }

        $this->usageValue = $value;
        $this->usageUnit = $unit;

        return true;
    }

    /**
     * @return Collection<int, MaintenanceRecord>
     */
    public function getMaintenanceRecords(): Collection
    {
        return $this->maintenanceRecords;
    }

    /**
     * Suppress a projected (history-derived) fact key from the card as of $at. A logged part whose
     * visit is no newer than $at is hidden; a later fitment re-surfaces it.
     */
    public function suppressFactKey(string $key, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->suppressedFactKeys[$key] = $at->format(\DateTimeInterface::ATOM);
    }

    /** The instant a projected fact key was suppressed, or null if it is not suppressed. */
    public function factSuppressedAt(string $key): ?\DateTimeImmutable
    {
        $at = $this->suppressedFactKeys[$key] ?? null;

        return null !== $at ? new \DateTimeImmutable($at) : null;
    }

    public function addMaintenanceRecord(MaintenanceRecord $record): void
    {
        if (!$this->maintenanceRecords->contains($record)) {
            $this->maintenanceRecords->add($record);
        }
    }

    /**
     * @return Collection<int, PlannedMaintenance>
     */
    public function getPlannedTasks(): Collection
    {
        return $this->plannedTasks;
    }

    public function addPlannedTask(PlannedMaintenance $task): void
    {
        if (!$this->plannedTasks->contains($task)) {
            $this->plannedTasks->add($task);
        }
    }

    /**
     * The open, actionable tasks — the live checklist. Done/dismissed tasks drop off, and so do
     * KNOWN spec carriers (open but untargeted): those feed the facts projection, not the to-do list.
     *
     * @return list<PlannedMaintenance>
     */
    public function openTasks(): array
    {
        return array_values(array_filter(
            $this->plannedTasks->toArray(),
            static fn (PlannedMaintenance $task): bool => $task->isOpen() && $task->isActionable(),
        ));
    }

    public function openTaskCount(): int
    {
        return \count($this->openTasks());
    }

    /**
     * Upsert a recurring rule, keyed by {@see ServiceInterval::key()} — re-setting a key replaces it
     * in place (a refreshed interval, or a fix), unmentioned keys stay. Additive like the fact store.
     */
    public function setServiceInterval(ServiceInterval $interval): void
    {
        $this->serviceSchedule->set($interval->key(), $interval);
    }

    public function serviceIntervalByKey(string $key): ?ServiceInterval
    {
        return $this->serviceSchedule->get($key);
    }

    /** A live (non-tombstoned) rule by its ULID — the handle the dashboard DELETE addresses. */
    public function serviceIntervalById(string $id): ?ServiceInterval
    {
        foreach ($this->serviceSchedule as $interval) {
            if ($interval->getId() === $id && !$interval->isDeleted()) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * The live recurring schedule — tombstoned rules drop off. This is what the forecast reads.
     *
     * @return list<ServiceInterval>
     */
    public function serviceSchedule(): array
    {
        return array_values(array_filter(
            $this->serviceSchedule->toArray(),
            static fn (ServiceInterval $interval): bool => !$interval->isDeleted(),
        ));
    }
}
