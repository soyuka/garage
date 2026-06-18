<?php

declare(strict_types=1);

namespace App\Entity;

use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;
use App\Repository\MaintenanceRecordRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A dated service event performed on a vehicle (oil change, filter swap, tyres…).
 *
 * Distinct from VehicleFact: a fact is a *spec* (oil_capacity = 1.1 L, one row per key,
 * UNIQUE(vehicle, key)); a MaintenanceRecord is an *event* — append-only, many documents
 * of the same type over time are the whole point (full oil-change history). NEVER add a
 * unique constraint on (vehicle, type) here.
 *
 * `owner` is the same multi-tenancy seam as Vehicle: null = shared/global in phase 1.
 */
#[ODM\Document(collection: 'maintenance_records', repositoryClass: MaintenanceRecordRepository::class)]
#[ODM\Index(keys: ['vehicle' => 'asc'], name: 'idx_maintenance_vehicle')]
#[ODM\Index(keys: ['vehicle' => 'asc', 'type' => 'asc'], name: 'idx_maintenance_vehicle_type')]
class MaintenanceRecord
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    public function __construct(
        #[ODM\ReferenceOne(targetDocument: Vehicle::class, storeAs: 'id', inversedBy: 'maintenanceRecords')]
        private Vehicle $vehicle,
        #[ODM\Field(type: 'string', enumType: MaintenanceType::class)]
        private MaintenanceType $type,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $performedAt,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $label = null,
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $usageValue = null,
        #[ODM\Field(type: 'string', enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $usageUnit = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $notes = null,
        // Money as integer minor units (cents) — never a binary float.
        #[ODM\Field(type: 'int', nullable: true)]
        private ?int $costCents = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $currency = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $performedBy = null,
        /** @var list<array{ref: string, label?: string}> */
        #[ODM\Field(type: 'collection')]
        private array $partsUsed = [],
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $owner = null,
    ) {
        $this->id = (string) new Ulid();
        $vehicle->addMaintenanceRecord($this);
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getType(): MaintenanceType
    {
        return $this->type;
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

    /**
     * @return list<array{ref: string, label?: string}>
     */
    public function getPartsUsed(): array
    {
        return $this->partsUsed;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }
}
