<?php

declare(strict_types=1);

namespace App\Entity;

use App\Maintenance\UsageUnit;
use App\Repository\MaintenanceRecordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A dated service VISIT performed on a vehicle (a vidange that swapped oil + oil filter + fuel
 * filter in one go). One visit carries a list of {@see $items} line-items — each its own service
 * type with the parts it consumed — so the whole visit is ONE history row, not one row per part.
 * Cost, performedBy, notes and the usage reading are visit-level (the bill is for the visit).
 *
 * Distinct from VehicleFact: a fact is a *spec* (oil_capacity = 1.1 L, one row per key,
 * UNIQUE(vehicle, key)); a MaintenanceRecord is an *event* — append-only, many visits over time
 * are the whole point (full service history). NEVER add a unique constraint on (vehicle, type) here.
 *
 * Forecasting ({@see \App\Maintenance\DueMaintenanceCalculator}) keys off the per-item type, so a
 * visit that changed N services seeds N independent due-dates from one row.
 *
 * `owner` is the same multi-tenancy seam as Vehicle: null = shared/global in phase 1.
 */
// Both indexes are sort-covering: history() matches on vehicle (optionally an item type) and sorts
// performedAt desc, so the sort key rides in the index and a limited query early-cuts instead of
// blocking on an in-memory sort. A bare {vehicle} index would be a redundant prefix of these.
// items.type is a multikey path (one index entry per line-item) so the type-narrowed history still
// hits the index.
#[ODM\Document(collection: 'maintenance_records', repositoryClass: MaintenanceRecordRepository::class)]
#[ODM\Index(keys: ['vehicle' => 'asc', 'performedAt' => 'desc', 'id' => 'desc'], name: 'idx_maintenance_vehicle_performed')]
#[ODM\Index(keys: ['vehicle' => 'asc', 'items.type' => 'asc', 'performedAt' => 'desc'], name: 'idx_maintenance_vehicle_type_performed')]
class MaintenanceRecord
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    /**
     * The visit's line-items, each a service type with the parts it consumed. Declared class-level
     * (not constructor-promoted) so a legacy document predating this field hydrates to [] instead of
     * leaving a typed property uninitialised — newInstanceWithoutConstructor skips promoted defaults.
     *
     * @var list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string}>}>
     */
    #[ODM\Field(type: 'collection')]
    private array $items = [];

    /**
     * The visit's bills (PDF/JPEG/PNG), one embedded metadata doc per file; the binaries live in the
     * object store. Declared class-level and initialised in the constructor — ODM hydrates a record
     * predating this field to an empty collection, so a legacy document never trips on it.
     *
     * @var Collection<int, MaintenanceAttachment>
     */
    #[ODM\EmbedMany(targetDocument: MaintenanceAttachment::class)]
    private Collection $attachments;

    /**
     * @param list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string}>}> $items
     */
    public function __construct(
        #[ODM\ReferenceOne(targetDocument: Vehicle::class, storeAs: 'id', inversedBy: 'maintenanceRecords')]
        private Vehicle $vehicle,
        array $items,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $performedAt,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        // Visit label, e.g. "révision annuelle"; per-item labels live on each item.
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $label = null,
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $usageValue = null,
        #[ODM\Field(type: 'string', enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $usageUnit = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $notes = null,
        // Money as integer minor units (cents) — never a binary float. Visit-level: the bill total.
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $costCents = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $currency = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $performedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $owner = null,
    ) {
        $this->items = self::normaliseItems($items);
        $this->attachments = new ArrayCollection();
        $this->id = (string) new Ulid();
        $vehicle->addMaintenanceRecord($this);
    }

    /**
     * Validate + normalise a visit's line-items: drop empties, keep {type, optional label, parts}.
     *
     * @param list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string}>}> $items
     *
     * @return list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string}>}>
     */
    private static function normaliseItems(array $items): array
    {
        if ([] === $items) {
            throw new \InvalidArgumentException('A maintenance visit must carry at least one item.');
        }

        return array_map(static function (array $item): array {
            $type = $item['type'] ?? '';
            if ('' === $type) {
                throw new \InvalidArgumentException('Every maintenance item must carry a non-empty type.');
            }

            $normalised = ['type' => $type, 'parts' => array_values($item['parts'] ?? [])];
            if (isset($item['label']) && '' !== $item['label']) {
                $normalised['label'] = $item['label'];
            }

            return $normalised;
        }, array_values($items));
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    /**
     * @return list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string}>}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return list<MaintenanceAttachment> */
    public function getAttachments(): array
    {
        return array_values($this->attachments->toArray());
    }

    public function addAttachment(MaintenanceAttachment $attachment): void
    {
        $this->attachments->add($attachment);
    }

    public function findAttachment(string $id): ?MaintenanceAttachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->getId() === $id) {
                return $attachment;
            }
        }

        return null;
    }

    /** Detach a bill by its id; returns false when the visit has no such attachment. */
    public function removeAttachment(string $id): bool
    {
        $attachment = $this->findAttachment($id);
        if (null === $attachment) {
            return false;
        }

        $this->attachments->removeElement($attachment);

        return true;
    }

    /**
     * The distinct service types this visit covered — the keys forecasting and history-filtering use.
     *
     * @return list<string>
     */
    public function getTypes(): array
    {
        return array_values(array_unique(array_column($this->items, 'type')));
    }

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getUsageValue(): ?int
    {
        return $this->usageValue;
    }

    public function getUsageUnit(): ?UsageUnit
    {
        return $this->usageUnit;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCostCents(): ?int
    {
        return $this->costCents;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getPerformedBy(): ?string
    {
        return $this->performedBy;
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
     * Manual edit from the dashboard — the one exception to the append-only rule. Replaces the whole
     * visit (items + visit-level fields); forecasting re-keys off the fresh item types on next read.
     *
     * @param list<array{type: string, label?: string, parts?: list<array{ref: string, brand?: string, label?: string}>}> $items
     */
    public function replaceItems(array $items): void
    {
        $this->items = self::normaliseItems($items);
    }

    public function setPerformedAt(\DateTimeImmutable $performedAt): void
    {
        $this->performedAt = $performedAt;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function setUsageValue(?int $usageValue): void
    {
        $this->usageValue = $usageValue;
    }

    public function setUsageUnit(?UsageUnit $usageUnit): void
    {
        $this->usageUnit = $usageUnit;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function setCostCents(?int $costCents): void
    {
        $this->costCents = $costCents;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function setPerformedBy(?string $performedBy): void
    {
        $this->performedBy = $performedBy;
    }
}
