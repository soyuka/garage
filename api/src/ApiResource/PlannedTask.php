<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\ArchiveTasksOutput;
use App\Dto\PlannedTaskInput;
use App\Entity\PlannedMaintenance;
use App\State\ArchiveCompletedTasksProcessor;
use App\State\PlannedTaskCreateProcessor;
use App\State\PlannedTaskPatchProcessor;
use App\State\PlannedTaskProvider;
use App\State\VehiclePlannedTaskCollectionProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a PlannedMaintenance task — one open item on the garage
 * checklist. Its ULID-keyed IRI (/garage/planned-tasks/{id}) is surfaced as `@id` so the dashboard
 * can tick it off: the PATCH flips `status` (the only writable field) to "done"/"dismissed". All
 * identity/content fields are read-only — the agent owns those through plan_maintenance.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/garage/vehicles/{vehicleId}/planned-tasks',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            provider: VehiclePlannedTaskCollectionProvider::class,
        ),
        // The dashboard's manual task add: a checklist item keyed by hand (source "user").
        new Post(
            uriTemplate: '/garage/vehicles/{vehicleId}/planned-tasks',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            input: PlannedTaskInput::class,
            processor: PlannedTaskCreateProcessor::class,
        ),
        // "Archiver les entretiens terminés": file every done task into the past-maintenance log.
        // No request body (input: false) and no resource read (read: false) — it acts on the
        // vehicle's whole done set and returns a small summary, not a PlannedTask.
        new Post(
            uriTemplate: '/garage/vehicles/{vehicleId}/planned-tasks/archive',
            uriVariables: [
                'vehicleId' => new Link(fromClass: GarageVehicle::class, identifiers: ['id']),
            ],
            status: 200,
            input: false,
            output: ArchiveTasksOutput::class,
            read: false,
            processor: ArchiveCompletedTasksProcessor::class,
        ),
        new Get(uriTemplate: '/garage/planned-tasks/{id}', provider: PlannedTaskProvider::class),
        new Patch(
            uriTemplate: '/garage/planned-tasks/{id}',
            provider: PlannedTaskProvider::class,
            processor: PlannedTaskPatchProcessor::class,
        ),
    ],
)]
final class PlannedTask
{
    #[ApiProperty(identifier: true, description: 'Public ULID identifier of the planned task.')]
    public Ulid $id;

    #[ApiProperty(writable: false)]
    public string $type;
    #[ApiProperty(writable: false)]
    public ?string $label = null;
    /** @var array{value: int, unit: string|null}|null */
    #[ApiProperty(writable: false, description: 'Usage reading the work is pegged to, e.g. {"value":250000,"unit":"km"}.')]
    public ?array $target = null;
    #[ApiProperty(writable: false)]
    public ?string $dueDate = null;
    /** @var list<array{ref: string, label?: string, brand?: string, qty?: int, spec?: array{verified: bool, viscosity?: string, standard?: string, capacity?: string, unit?: string}}> */
    #[ApiProperty(description: 'Parts/fluids the task needs — the shopping list. A PATCH MERGES the supplied parts in (dedup by ref); it never replaces or clears the list. A ref-less fluid line carries a read-only derived `spec` (which oil, what quantity) projected from the vehicle facts.')]
    public array $parts = [];
    #[ApiProperty(description: 'Lifecycle: "open" | "done" | "dismissed". Set "done" to tick the task off.')]
    public string $status;
    #[ApiProperty(writable: false, description: 'IRI of the vehicle this task belongs to.')]
    public string $vehicle;
    #[ApiProperty(writable: false)]
    public string $createdAt;
    #[ApiProperty(writable: false)]
    public ?string $completedAt = null;

    /**
     * @param list<array<string, mixed>>|null $parts derived (fluid-enriched) parts; defaults to the stored parts
     */
    public static function fromEntity(PlannedMaintenance $task, ?array $parts = null): self
    {
        $resource = new self();
        $resource->id = $task->getId();
        $resource->type = $task->getType();
        $resource->label = $task->getLabel();
        $resource->target = null !== $task->getTargetUsageValue()
            ? ['value' => $task->getTargetUsageValue(), 'unit' => $task->getTargetUsageUnit()?->value]
            : null;
        $resource->dueDate = $task->getDueDate()?->format('Y-m-d');
        $resource->parts = $parts ?? $task->getParts();
        $resource->status = $task->getStatus()->value;
        $resource->vehicle = '/garage/vehicles/'.$task->getVehicle()->getId();
        $resource->createdAt = $task->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $resource->completedAt = $task->getCompletedAt()?->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
