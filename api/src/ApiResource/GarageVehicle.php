<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\VehicleFactInput;
use App\Entity\Vehicle;
use App\State\GarageVehicleCollectionProvider;
use App\State\GarageVehicleFactProcessor;
use App\State\GarageVehiclePatchProcessor;
use App\State\GarageVehicleProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a garage Vehicle (Entity\Vehicle). Its ULID-keyed IRI
 * (/garage/vehicles/{id}) is the handle the MCP tools surface as `@id` and accept back as the
 * `vehicle` input — so an agent can follow a listed vehicle to a real GET instead of juggling a
 * leaked DB integer. Distinct from the catalog App\ApiResource\Vehicle (SIV, plate-keyed).
 *
 * The PATCH is the dashboard editable-card write: a JSON Merge Patch on the vehicle's own scalars
 * (usage reading, plate). Identity/provenance fields are read-only (writable: false) so the card
 * can never rewrite make/model; the agent owns identity through the MCP tools' dedup path.
 */
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/garage/vehicles', provider: GarageVehicleCollectionProvider::class),
        new Get(uriTemplate: '/garage/vehicles/{id}', provider: GarageVehicleProvider::class),
        new Patch(
            uriTemplate: '/garage/vehicles/{id}',
            provider: GarageVehicleProvider::class,
            processor: GarageVehiclePatchProcessor::class,
        ),
        // The dashboard's manual fact write: record one user-supplied spec on the vehicle. Input is
        // a bare {key, value, unit} (VehicleFactInput); the processor returns the fresh vehicle.
        new Post(
            uriTemplate: '/garage/vehicles/{id}/facts',
            input: VehicleFactInput::class,
            processor: GarageVehicleFactProcessor::class,
        ),
    ],
)]
final class GarageVehicle
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the garage vehicle.')]
    public Ulid $id;

    #[ApiProperty(writable: false)]
    public string $make;
    #[ApiProperty(writable: false)]
    public string $model;
    #[ApiProperty(writable: false)]
    public ?int $year = null;
    #[ApiProperty(writable: false)]
    public string $vehicleType;
    #[ApiProperty(writable: false)]
    public string $source;
    #[ApiProperty(writable: false, description: 'TecDoc kType, when known.')]
    public ?int $kType = null;
    public ?string $plate = null;
    #[ApiProperty(writable: false)]
    public ?string $vin = null;
    #[ApiProperty(description: 'Last recorded usage reading (odometer/engine-hours), when known.')]
    public ?int $usageValue = null;
    #[ApiProperty(description: 'Unit of the usage reading: "km", "mi" or "h" (engine hours). Must be set together with usageValue.')]
    public ?string $usageUnit = null;
    // Projected from the vehicle's maintenance items (the single fact store); set by the providers,
    // not by fromEntity — the entity holds no facts of its own.
    #[ApiProperty(writable: false)]
    public int $factCount = 0;
    /**
     * The vehicle's facts with provenance (key/value/unit/source/verified), projected from its
     * maintenance items. Populated on the item GET only; the collection leaves it empty so the parc
     * listing stays light.
     *
     * @var list<array{key: string, value: string, unit: string|null, source: string, verified: bool, addedAt: string, url: string|null}>
     */
    #[ApiProperty(writable: false)]
    public array $facts = [];
    // The open checklist is its own subresource — GET /garage/vehicles/{id}/planned-tasks (a
    // collection of PlannedTask), which the dashboard fetches on select. Its length is the open
    // count; there is no openTasks scalar on the vehicle.
    #[ApiProperty(writable: false)]
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
        $resource->usageValue = $vehicle->getUsageValue();
        $resource->usageUnit = $vehicle->getUsageUnit()?->value;
        $resource->addedAt = $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
