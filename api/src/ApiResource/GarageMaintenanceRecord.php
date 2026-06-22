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
use App\Dto\MaintenanceRecordInput;
use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceRecord;
use App\State\GarageMaintenanceRecordProvider;
use App\State\MaintenanceRecordCreateProcessor;
use App\State\MaintenanceRecordDeleteProcessor;
use App\State\MaintenanceRecordUpdateProcessor;
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
        // Edit a logged visit by hand (owner-gated). Replaces the items/visit fields it carries.
        new Patch(
            uriTemplate: '/garage/maintenance-records/{id}',
            input: MaintenanceRecordInput::class,
            read: false,
            processor: MaintenanceRecordUpdateProcessor::class,
        ),
        // Remove a logged visit (owner-gated).
        new Delete(
            uriTemplate: '/garage/maintenance-records/{id}',
            read: false,
            processor: MaintenanceRecordDeleteProcessor::class,
        ),
    ],
)]
final class GarageMaintenanceRecord
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the maintenance record.')]
    public Ulid $id;

    /** @var list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string}>}> */
    public array $items = [];

    /**
     * The visit's bills, each {id, filename, contentType, size, url}. `url` is the download IRI the
     * dashboard fetches (with its Bearer) to render the file. Read-only here — bills are written
     * through the dedicated multipart upload/delete operations, not this read-model.
     *
     * @var list<array{id: string, filename: string, contentType: string, size: int, url: string}>
     */
    public array $attachments = [];
    public ?string $label = null;
    public string $performedAt;
    public ?int $usageValue = null;
    public ?string $usageUnit = null;
    public ?string $notes = null;
    public ?string $performedBy = null;
    public ?int $costCents = null;
    public ?string $currency = null;
    public string $source;
    public string $addedAt;

    public static function fromEntity(MaintenanceRecord $record): self
    {
        $resource = new self();
        $resource->id = $record->getId();
        $resource->items = $record->getItems();
        $recordId = (string) $record->getId();
        $resource->attachments = array_map(
            static fn (MaintenanceAttachment $a): array => self::attachmentView($a, $recordId),
            $record->getAttachments(),
        );
        $resource->label = $record->getLabel();
        $resource->performedAt = $record->getPerformedAt()->format('Y-m-d');
        $resource->usageValue = $record->getUsageValue();
        $resource->usageUnit = $record->getUsageUnit()?->value;
        $resource->notes = $record->getNotes();
        $resource->performedBy = $record->getPerformedBy();
        $resource->costCents = $record->getCostCents();
        $resource->currency = $record->getCurrency();
        $resource->source = $record->getSource();
        $resource->addedAt = $record->getAddedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }

    /**
     * The wire shape of one bill, shared by this read-model and the upload endpoint's 201 body so the
     * download IRI is built in exactly one place.
     *
     * @return array{id: string, filename: string, contentType: string, size: int, url: string}
     */
    public static function attachmentView(MaintenanceAttachment $attachment, string $recordId): array
    {
        return [
            'id' => $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'contentType' => $attachment->getContentType(),
            'size' => $attachment->getSize(),
            'url' => sprintf('/garage/maintenance-records/%s/attachments/%s', $recordId, $attachment->getId()),
        ];
    }
}
