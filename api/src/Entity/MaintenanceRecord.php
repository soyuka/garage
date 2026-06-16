<?php

declare(strict_types=1);

namespace App\Entity;

use App\Maintenance\MaintenanceType;
use App\Maintenance\UsageUnit;
use App\Repository\MaintenanceRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A dated service event performed on a vehicle (oil change, filter swap, tyres…).
 *
 * Distinct from VehicleFact: a fact is a *spec* (oil_capacity = 1.1 L, one row per key,
 * UNIQUE(vehicle_id, fact_key)); a MaintenanceRecord is an *event* — append-only, many rows
 * of the same type over time are the whole point (full oil-change history). NEVER add a
 * unique constraint on (vehicle, type) here.
 *
 * `owner` is the same multi-tenancy seam as Vehicle: null = shared/global in phase 1.
 */
#[ORM\Entity(repositoryClass: MaintenanceRecordRepository::class)]
#[ORM\Index(name: 'idx_maintenance_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_maintenance_vehicle_type', columns: ['vehicle_id', 'type'])]
class MaintenanceRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'maintenanceRecords')]
        #[ORM\JoinColumn(nullable: false)]
        private Vehicle $vehicle,
        #[ORM\Column(enumType: MaintenanceType::class)]
        private MaintenanceType $type,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $performedAt,
        #[ORM\Column]
        private string $source,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ORM\Column(nullable: true)]
        private ?string $label = null,
        #[ORM\Column(nullable: true)]
        private ?int $usageValue = null,
        #[ORM\Column(enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $usageUnit = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes = null,
        // Money as integer minor units (cents) — never a binary float.
        #[ORM\Column(nullable: true)]
        private ?int $costCents = null,
        #[ORM\Column(length: 3, nullable: true)]
        private ?string $currency = null,
        #[ORM\Column(nullable: true)]
        private ?string $performedBy = null,
        /** @var list<array{ref: string, label?: string}> */
        #[ORM\Column(type: 'json')]
        private array $partsUsed = [],
        #[ORM\Column(nullable: true)]
        private ?string $addedBy = null,
        #[ORM\Column(nullable: true)]
        private ?string $owner = null,
    ) {
        $this->id = new Ulid();
        $vehicle->addMaintenanceRecord($this);
    }

    public function getId(): Ulid
    {
        return $this->id;
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
