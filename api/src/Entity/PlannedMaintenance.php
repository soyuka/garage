<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\UsageUnit;
use App\Maintenance\MaintenanceType;
use App\Maintenance\PlannedMaintenanceStatus;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A planned maintenance task: work the user INTENDS to do, not yet done — the unit of the garage
 * checklist. Carries the parts/fluids it needs (the shopping list) and an optional target the work
 * is pegged to (a usage reading like "à 250 000 km", or a date).
 *
 * Three siblings on a Vehicle, deliberately distinct:
 *  - VehicleFact     = a spec (oil_capacity), one row per key;
 *  - MaintenanceRecord = an event that HAPPENED, append-only history;
 *  - PlannedMaintenance = an open intent that resolves to a record once performed.
 *
 * Dedup is logical (one open task per vehicle+type+label, enforced in the planner), NOT a DB unique
 * constraint — a done task and a fresh open task of the same type must coexist. `owner` is the same
 * multi-tenancy seam as Vehicle: null = shared/global in phase 1.
 *
 * @see \App\Maintenance\PlannedMaintenancePlanner
 */
#[ORM\Entity(repositoryClass: PlannedMaintenanceRepository::class)]
#[ORM\Index(name: 'idx_planned_vehicle_status', columns: ['vehicle_id', 'status'])]
class PlannedMaintenance
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'plannedTasks')]
        #[ORM\JoinColumn(nullable: false)]
        private Vehicle $vehicle,
        #[ORM\Column(enumType: MaintenanceType::class)]
        private MaintenanceType $type,
        #[ORM\Column]
        private string $source,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(enumType: PlannedMaintenanceStatus::class)]
        private PlannedMaintenanceStatus $status = PlannedMaintenanceStatus::Open,
        #[ORM\Column(nullable: true)]
        private ?string $label = null,
        #[ORM\Column(nullable: true)]
        private ?int $targetUsageValue = null,
        #[ORM\Column(enumType: UsageUnit::class, nullable: true)]
        private ?UsageUnit $targetUsageUnit = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $dueDate = null,
        /** @var list<array{ref: string, label?: string, brand?: string, qty?: int}> */
        #[ORM\Column(type: 'json')]
        private array $parts = [],
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $completedAt = null,
        #[ORM\Column(nullable: true)]
        private ?string $addedBy = null,
        #[ORM\Column(nullable: true)]
        private ?string $owner = null,
    ) {
        $this->id = new Ulid();
        $vehicle->addPlannedTask($this);
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
