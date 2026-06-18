<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VehicleFactRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A typed fact about a vehicle (oil_type, oil_capacity, spark_plug_ref…) with full
 * provenance. `verified` is what stops a hallucinated capacity becoming a confident lie,
 * and doubles as the future promotion marker (see docs/TODO.md "## Garage").
 */
#[ODM\Document(collection: 'vehicle_facts', repositoryClass: VehicleFactRepository::class)]
#[ODM\UniqueIndex(keys: ['vehicle' => 'asc', 'key' => 'asc'], name: 'uniq_vehicle_fact_key')]
class VehicleFact
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    public function __construct(
        #[ODM\ReferenceOne(targetDocument: Vehicle::class, storeAs: 'id', inversedBy: 'facts')]
        private Vehicle $vehicle,
        #[ODM\Field(type: 'string')]
        private string $key,
        #[ODM\Field(type: 'string')]
        private string $value,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'bool')]
        private bool $verified,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $unit = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $url = null,
    ) {
        $this->id = (string) new Ulid();
        $vehicle->addFact($this);
    }

    /**
     * Overwrite the mutable fields of an existing fact, keeping identity (vehicle + key) and
     * primary key stable. Used by the resolver to update a fact in place instead of inserting
     * a duplicate row for the same key (UNIQUE(vehicle, key)).
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

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id);
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
