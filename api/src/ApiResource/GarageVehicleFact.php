<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\VehicleFact;
use App\State\GarageVehicleFactProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a VehicleFact — a stored spec with provenance. Its
 * ULID-keyed IRI (/garage/vehicle-facts/{id}) is surfaced as `@id` by get_vehicle_facts so an
 * agent can address a single fact.
 */
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/garage/vehicle-facts/{id}', provider: GarageVehicleFactProvider::class),
    ],
)]
final class GarageVehicleFact
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the fact.')]
    public Ulid $id;

    public string $key;
    public string $value;
    public ?string $unit = null;
    public string $source;
    public bool $verified;
    public string $addedAt;
    public ?string $addedBy = null;
    public ?string $url = null;

    public static function fromEntity(VehicleFact $fact): self
    {
        $resource = new self();
        $resource->id = $fact->getId();
        $resource->key = $fact->getKey();
        $resource->value = $fact->getValue();
        $resource->unit = $fact->getUnit();
        $resource->source = $fact->getSource();
        $resource->verified = $fact->isVerified();
        $resource->addedAt = $fact->getAddedAt()->format(\DateTimeInterface::ATOM);
        $resource->addedBy = $fact->getAddedBy();
        $resource->url = $fact->getUrl();

        return $resource;
    }
}
