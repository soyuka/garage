<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\UsageUnit;
use App\Maintenance\PlannedMaintenanceStatus;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A planned maintenance task: work the user INTENDS to do, not yet done — the unit of the garage
 * checklist. Carries the parts/fluids it needs (the shopping list) and an optional target the work
 * is pegged to (a usage reading like "à 250 000 km", or a date).
 *
 * Three siblings on a Vehicle, deliberately distinct:
 *  - VehicleFact     = a spec (oil_capacity), one document per key;
 *  - MaintenanceRecord = an event that HAPPENED, append-only history;
 *  - PlannedMaintenance = an open intent that resolves to a record once performed.
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
