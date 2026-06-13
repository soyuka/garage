<?php

declare(strict_types=1);

namespace App\Entity;

use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, VehicleFact> */
    #[ORM\OneToMany(targetEntity: VehicleFact::class, mappedBy: 'vehicle', cascade: ['persist'], orphanRemoval: true)]
    private Collection $facts;

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
    ) {
        $this->facts = new ArrayCollection();
    }

    public function getId(): ?int
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

    public function getModification(): ?string
    {
        return $this->modification;
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

    public function getPlate(): ?string
    {
        return $this->plate;
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

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
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
}
