<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\Vehicle;
use App\State\GarageVehicleProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a garage Vehicle (Entity\Vehicle). Its ULID-keyed IRI
 * (/garage/vehicles/{id}) is the handle the MCP tools surface as `@id` and accept back as the
 * `vehicle` input — so an agent can follow a listed vehicle to a real GET instead of juggling a
 * leaked DB integer. Distinct from the catalog App\ApiResource\Vehicle (SIV, plate-keyed).
 */
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/garage/vehicles/{id}', provider: GarageVehicleProvider::class),
    ],
)]
final class GarageVehicle
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the garage vehicle.')]
    public Ulid $id;

    public string $make;
    public string $model;
    public ?int $year = null;
    public string $vehicleType;
    public string $source;
    #[ApiProperty(description: 'TecDoc kType, when known.')]
    public ?int $kType = null;
    public ?string $plate = null;
    public ?string $vin = null;
    public int $factCount = 0;
    public string $addedAt;

    public static function fromEntity(Vehicle $vehicle): self
    {
        $resource = new self();
        $resource->id = $vehicle->getId();
        $resource->make = $vehicle->getMake();
        $resource->model = $vehicle->getModel();
        $resource->year = $vehicle->getYear();
        $resource->vehicleType = $vehicle->getVehicleType()->value;
        $resource->source = $vehicle->getSource();
        $resource->kType = $vehicle->getKType();
        $resource->plate = $vehicle->getPlate();
        $resource->vin = $vehicle->getVin();
        $resource->factCount = $vehicle->getFacts()->count();
        $resource->addedAt = $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
