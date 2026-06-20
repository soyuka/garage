<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\MaintenanceRecordInput;
use App\Entity\MaintenanceRecord;
use App\State\GarageMaintenanceRecordProvider;
use App\State\MaintenanceRecordCreateProcessor;
use App\State\VehicleMaintenanceRecordCollectionProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a MaintenanceRecord — one dated service event. Its
 * ULID-keyed IRI (/garage/maintenance-records/{id}) is surfaced as `@id` by
 * get_maintenance_history so an agent can address a single logged event.
 */
#[ApiResource(
    operations: [
        // The dashboard's "past maintenance" rail: a vehicle's service history, newest first.
        new GetCollection(
            uriTemplate: '/garage/vehicles/{vehicleId}/maintenance-records',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            provider: VehicleMaintenanceRecordCollectionProvider::class,
        ),
        // Log a past service by hand (source "user").
        new Post(
            uriTemplate: '/garage/vehicles/{vehicleId}/maintenance-records',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            input: MaintenanceRecordInput::class,
            processor: MaintenanceRecordCreateProcessor::class,
        ),
        new Get(uriTemplate: '/garage/maintenance-records/{id}', provider: GarageMaintenanceRecordProvider::class),
    ],
)]
final class GarageMaintenanceRecord
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the maintenance record.')]
    public Ulid $id;

    public string $type;
    public ?string $label = null;
    public string $performedAt;
    public ?int $usageValue = null;
    public ?string $usageUnit = null;
    public ?string $notes = null;
    public ?string $performedBy = null;
    /** @var list<array{ref: string, label?: string}> */
    public array $partsUsed = [];
    public string $source;
    public string $addedAt;

    public static function fromEntity(MaintenanceRecord $record): self
    {
        $resource = new self();
        $resource->id = $record->getId();
        $resource->type = $record->getType();
        $resource->label = $record->getLabel();
        $resource->performedAt = $record->getPerformedAt()->format('Y-m-d');
        $resource->usageValue = $record->getUsageValue();
        $resource->usageUnit = $record->getUsageUnit()?->value;
        $resource->notes = $record->getNotes();
        $resource->performedBy = $record->getPerformedBy();
        $resource->partsUsed = $record->getPartsUsed();
        $resource->source = $record->getSource();
        $resource->addedAt = $record->getAddedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
