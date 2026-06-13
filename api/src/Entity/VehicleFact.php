<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VehicleFactRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A typed fact about a vehicle (oil_type, oil_capacity, spark_plug_ref…) with full
 * provenance. `verified` is what stops a hallucinated capacity becoming a confident lie,
 * and doubles as the future promotion marker (see docs/TODO.md "## Garage").
 */
#[ORM\Entity(repositoryClass: VehicleFactRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vehicle_fact_key', columns: ['vehicle_id', 'fact_key'])]
class VehicleFact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'facts')]
        #[ORM\JoinColumn(nullable: false)]
        private Vehicle $vehicle,
        // `key` is reserved in PostgreSQL — map to a safe column name.
        #[ORM\Column(name: 'fact_key')]
        private string $key,
        #[ORM\Column(type: 'text')]
        private string $value,
        #[ORM\Column]
        private string $source,
        #[ORM\Column]
        private bool $verified,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ORM\Column(nullable: true)]
        private ?string $unit = null,
        #[ORM\Column(nullable: true)]
        private ?string $addedBy = null,
        #[ORM\Column(nullable: true)]
        private ?string $url = null,
    ) {
        $vehicle->addFact($this);
    }

    /**
     * Overwrite the mutable fields of an existing fact, keeping identity (vehicle + key) and
     * primary key stable. Used by the resolver to update a fact in place instead of inserting
     * a duplicate row for the same key (UNIQUE(vehicle_id, fact_key)).
     */
    public function update(
        string $value,
        string $source,
        bool $verified,
        ?string $unit,
        ?string $addedBy,
        ?string $url,
        \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ): void {
        $this->value = $value;
        $this->source = $source;
        $this->verified = $verified;
        $this->unit = $unit;
        $this->addedBy = $addedBy;
        $this->url = $url;
        $this->addedAt = $addedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }
}
