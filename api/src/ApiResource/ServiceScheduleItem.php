<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Dto\ServiceScheduleInput;
use App\Entity\ServiceInterval;
use App\State\ServiceScheduleCollectionProvider;
use App\State\ServiceScheduleDeleteProcessor;
use App\State\ServiceScheduleItemProcessor;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of one {@see ServiceInterval} recurring rule — the dashboard's
 * editable "Programme d'entretien" card writes through it, the human counterpart of the agent's
 * set_maintenance_schedule tool. Mirrors the facts manual-CRUD seam on GarageVehicle: a vehicle-scoped
 * collection (GET/POST upsert) plus a by-id DELETE (soft tombstone). A hand-keyed rule lands as
 * source "user" (unverified) so it never outranks a catalog/SIV rule for the same key.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/garage/vehicles/{vehicleId}/service-schedule',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            provider: ServiceScheduleCollectionProvider::class,
        ),
        // Upsert keyed on (type, action, component): re-POSTing the same rule corrects its cadence in
        // place (keeping its id), a new key appends.
        new Post(
            uriTemplate: '/garage/vehicles/{vehicleId}/service-schedule',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            input: ServiceScheduleInput::class,
            processor: ServiceScheduleItemProcessor::class,
        ),
        new Delete(
            uriTemplate: '/garage/vehicles/{vehicleId}/service-schedule/{id}',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            read: false,
            processor: ServiceScheduleDeleteProcessor::class,
        ),
    ],
)]
final class ServiceScheduleItem
{
    #[ApiProperty(identifier: true, description: 'Stable ULID of the rule (the DELETE handle).')]
    public string $id;

    #[ApiProperty(writable: false, description: 'ULID of the owning vehicle.')]
    public string $vehicleId;

    #[ApiProperty(writable: false)]
    public string $type;
    #[ApiProperty(writable: false)]
    public string $action;
    #[ApiProperty(writable: false)]
    public ?string $component = null;
    /** @var array{value: int, unit: string|null}|null */
    #[ApiProperty(writable: false)]
    public ?array $everyUsage = null;
    #[ApiProperty(writable: false)]
    public ?int $everyMonths = null;
    #[ApiProperty(writable: false)]
    public ?int $firstUsageValue = null;
    #[ApiProperty(writable: false)]
    public string $source;
    #[ApiProperty(writable: false)]
    public bool $verified = false;
    #[ApiProperty(writable: false)]
    public ?string $url = null;

    public static function fromEntity(ServiceInterval $interval, Ulid $vehicleId): self
    {
        $resource = new self();
        $resource->id = $interval->getId();
        $resource->vehicleId = (string) $vehicleId;
        $resource->type = $interval->getType();
        $resource->action = $interval->getAction()->value;
        $resource->component = $interval->getComponent();
        $resource->everyUsage = null === $interval->getEveryUsageValue() ? null : [
            'value' => $interval->getEveryUsageValue(),
            'unit' => $interval->getEveryUsageUnit()?->value,
        ];
        $resource->everyMonths = $interval->getEveryMonths();
        $resource->firstUsageValue = $interval->getFirstUsageValue();
        $resource->source = $interval->getSource();
        $resource->verified = $interval->isVerified();
        $resource->url = $interval->getUrl();

        return $resource;
    }
}
