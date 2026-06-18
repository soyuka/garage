<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A vehicle held in the garage (the self-strengthening store), distinct from the
 * SIV-sourced API representation App\ApiResource\Vehicle.
 *
 * Phase 1 is a single shared garage: `owner` is the multi-tenancy seam — null means
 * shared/global. Per-user isolation later is a backfill + non-null migration, not a rewrite.
 */
#[ORM\Entity(repositoryClass: VehicleRepository::class)]
class Vehicle
{
    // App-assigned ULID public identifier — the dereferenceable IRI key (/garage/vehicles/{id}),
    // so the auto-increment DB row id is never exposed. Time-ordered, so it stays index-friendly.
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    /** @var Collection<int, VehicleFact> */
    #[ORM\OneToMany(targetEntity: VehicleFact::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true)]
    private Collection $facts;

    /** @var Collection<int, MaintenanceRecord> */
    #[ORM\OneToMany(targetEntity: MaintenanceRecord::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true)]
    private Collection $maintenanceRecords;

    /** @var Collection<int, PlannedMaintenance> */
    #[ORM\OneToMany(targetEntity: PlannedMaintenance::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true)]
    private Collection $plannedTasks;

    public function __construct(
        #[ORM\Column]
        private string $make,
        #[ORM\Column]
        private string $model,
        #[ORM\Column(enumType: VehicleType::class)]
        private VehicleType $vehicleType,
        #[ORM\Column]
        private string $source,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ORM\Column(nullable: true)]
        private ?int $year = null,
        #[ORM\Column(nullable: true)]
        private ?string $modification = null,
        #[ORM\Column(nullable: true)]
        private ?int $kType = null,
        #[ORM\Column(nullable: true)]
        private ?string $vin = null,
        #[ORM\Column(nullable: true)]
        private ?string $plate = null,
        #[ORM\Column(nullable: true)]
        private ?string $owner = null,
        #[ORM\Column(nullable: true)]
        private ?int $usageValue = null,
        #[ORM\Column(nullable: true, enumType: UsageUnit::class)]
        private ?UsageUnit $usageUnit = null,
    ) {
        $this->id = new Ulid();
        $this->facts = new ArrayCollection();
        $this->maintenanceRecords = new ArrayCollection();
        $this->plannedTasks = new ArrayCollection();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getMake(): string
    {
        return $this->make;
    }

    public function getModel(): string
    {
        return $this->model;
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
     * @return Collection<int, VehicleFact>
     */
    public function getFacts(): Collection
    {
        return $this->facts;
    }

    public function addFact(VehicleFact $fact): void
    {
        if (!$this->facts->contains($fact)) {
            $this->facts->add($fact);
        }
    }

    public function factByKey(string $key): ?VehicleFact
    {
        foreach ($this->facts as $fact) {
            if ($fact->getKey() === $key) {
                return $fact;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, MaintenanceRecord>
     */
    public function getMaintenanceRecords(): Collection
    {
        return $this->maintenanceRecords;
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
     * The open tasks only — the live checklist. Done/dismissed tasks drop off.
     *
     * @return list<PlannedMaintenance>
     */
    public function openTasks(): array
    {
        return array_values(array_filter(
            $this->plannedTasks->toArray(),
            static fn (PlannedMaintenance $task): bool => $task->isOpen(),
        ));
    }

    public function openTaskCount(): int
    {
        return \count($this->openTasks());
    }
}
