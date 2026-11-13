<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\VehicleCreateInput;
use App\Dto\VehicleFactInput;
use App\Dto\VehicleSaveInput;
use App\Entity\Vehicle;
use App\State\GarageVehicleCollectionProvider;
use App\State\GarageVehicleCreateProcessor;
use App\State\GarageVehicleDeleteProcessor;
use App\State\GarageVehicleFactDeleteProcessor;
use App\State\GarageVehicleFactProcessor;
use App\State\GarageVehiclePatchProcessor;
use App\State\GarageVehicleProvider;
use App\State\GarageVehicleSaveProcessor;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a garage Vehicle (Entity\Vehicle). Its ULID-keyed IRI
 * (/garage/vehicles/{id}) is the handle the MCP tools surface as `@id` and accept back as the
 * `vehicle` input — so an agent can follow a listed vehicle to a real GET instead of juggling a
 * leaked DB integer. Distinct from the catalog App\ApiResource\Vehicle (SIV, plate-keyed).
 *
 * The PATCH is the dashboard editable-card write: a JSON Merge Patch on the card's fields. The agent
 * owns identity through the MCP tools' dedup path, but a human editing their OWN card may correct any
 * field — including make/model — so the card's scalars are writable. Provenance (source, vin) and the
 * projected facts stay read-only. POST creates a blank vehicle to fill in by hand; DELETE soft-deletes.
 */
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/garage/vehicles', provider: GarageVehicleCollectionProvider::class),
        // Create a blank vehicle the user then fills in via the editable card. Body is optional
        // (VehicleCreateInput); the processor seeds placeholders and returns the fresh vehicle.
        new Post(
            uriTemplate: '/garage/vehicles',
            input: VehicleCreateInput::class,
            processor: GarageVehicleCreateProcessor::class,
        ),
        // The finder's "Enregistrer le véhicule": plate-aware find-or-create (201 created, 200 matched,
        // 409 + candidates when a plateless save could be a stored car). See GarageVehicleSaveProcessor.
        new Post(
            uriTemplate: '/garage/vehicles/save',
            input: VehicleSaveInput::class,
            processor: GarageVehicleSaveProcessor::class,
        ),
        new Get(uriTemplate: '/garage/vehicles/{id}', provider: GarageVehicleProvider::class),
        new Patch(
            uriTemplate: '/garage/vehicles/{id}',
            provider: GarageVehicleProvider::class,
            processor: GarageVehiclePatchProcessor::class,
        ),
        // Soft-delete: tombstone the vehicle (its history survives) and fan a 'deleted' event out.
        new Delete(
            uriTemplate: '/garage/vehicles/{id}',
            provider: GarageVehicleProvider::class,
            processor: GarageVehicleDeleteProcessor::class,
        ),
        // The dashboard's manual fact write: record one user-supplied spec on the vehicle. Input is
        // a bare {key, value, unit} (VehicleFactInput); the processor returns the fresh vehicle.
        new Post(
            uriTemplate: '/garage/vehicles/{id}/facts',
            input: VehicleFactInput::class,
            processor: GarageVehicleFactProcessor::class,
        ),
        // Soft-delete a fact by key: tombstones every matching attribute across the vehicle's items so
        // the projection drops it. Recoverable (re-POST the key), and a verified catalog fact can be
        // hidden too — the user's card, the user's call.
        new Delete(
            uriTemplate: '/garage/vehicles/{id}/facts/{key}',
            uriVariables: [
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
                'key' => new Link(fromClass: self::class, identifiers: ['key']),
            ],
            read: false,
            processor: GarageVehicleFactDeleteProcessor::class,
        ),
    ],
)]
final class GarageVehicle
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the garage vehicle.')]
    public Ulid $id;

    public string $make = '';
    public string $model = '';
    public ?int $year = null;
    #[ApiProperty(description: 'Engine/trim variant label, e.g. "2.0 16V Coupé". Pins the oil-grade variant (get_motul_oil) and the catalog modification. NEVER a fluid/oil grade.')]
    public ?string $engineVariant = null;
    #[ApiProperty(description: 'Manufacturer engine code (e.g. "WJY", "F1AE0481B") or a plain displacement label ("1.9") — the motor a mechanic asks for.')]
    public ?string $engineCode = null;
    #[ApiProperty(description: 'Vehicle segment: "car", "motorcycle", "cv" (commercial) or "groundscare".')]
    public string $vehicleType = 'car';
    #[ApiProperty(writable: false)]
    public string $source;
    #[ApiProperty(description: 'TecDoc kType, when known.')]
    public ?int $kType = null;
    #[ApiProperty(writable: false, description: 'TecDoc vehicle type to pass as carType with kType: PC, CV or MC (motorbike). Null for a segment the catalogue does not cover.')]
    public ?string $carType = null;
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
    /**
     * The composed maintenance health badge — status (green/amber/red/unknown), a 0–100 score (null
     * when unknown), and the breakdown (overdue/dueSoon/tracked/gaps) it explains itself with.
     * Computed on read from the forecast; populated on the item GET only, like facts.
     *
     * @var array{status: string, score: int|null, overdue: int, dueSoon: int, tracked: int, gaps: int}|null
     */
    #[ApiProperty(writable: false)]
    public ?array $health = null;
    /**
     * Compact summary of the soonest predicted service — the parc listing carries it so the sidebar
     * rail shows "next due" at a glance, without a per-vehicle forecast fetch. Null when nothing is
     * predictable yet (no logged history). The full forecast is GET /garage/vehicles/{id}/forecast.
     *
     * @var array{type: string, action: string|null, label: string, rule: string, overdue: bool, dueAt: string|null, projectedDueAt: string|null, daysUntilDue: int|null, usageRemaining: int|null, usageUnit: string|null}|null
     */
    #[ApiProperty(writable: false)]
    public ?array $nextDue = null;
    // The open checklist is its own subresource — GET /garage/vehicles/{id}/planned-tasks (a
    // collection of PlannedTask), which the dashboard fetches on select. Its length is the open
    // count; there is no openTasks scalar on the vehicle.
    #[ApiProperty(writable: false)]
    public string $addedAt;
    #[ApiProperty(writable: false, description: 'ISO-8601 instant the vehicle was archived (sold, scrapped, no longer driven), or null while it is in the active parc.')]
    public ?string $archivedAt = null;
    #[ApiProperty(description: 'Write-only switch for PATCH: true archives the vehicle (keeps the original date on a repeat), false restores it. Reads mirror archivedAt.')]
    public bool $archived = false;

    public static function fromEntity(Vehicle $vehicle): self
    {
        $resource = new self();
        $resource->id = $vehicle->getId();
        $resource->make = $vehicle->getMake();
        $resource->model = $vehicle->getModel();
        $resource->year = $vehicle->getYear();
        $resource->engineVariant = $vehicle->getEngineVariant();
        $resource->engineCode = $vehicle->getEngineCode();
        $resource->vehicleType = $vehicle->getVehicleType()->value;
        $resource->source = $vehicle->getSource();
        $resource->kType = $vehicle->getKType();
        $resource->carType = $vehicle->getVehicleType()->carType()?->value;
        $resource->plate = $vehicle->getPlate();
        $resource->vin = $vehicle->getVin();
        $resource->usageValue = $vehicle->getUsageValue();
        $resource->usageUnit = $vehicle->getUsageUnit()?->value;
        $resource->addedAt = $vehicle->getAddedAt()->format(\DateTimeInterface::ATOM);
        $resource->archivedAt = $vehicle->getArchivedAt()?->format(\DateTimeInterface::ATOM);
        $resource->archived = $vehicle->isArchived();

        return $resource;
    }
}
