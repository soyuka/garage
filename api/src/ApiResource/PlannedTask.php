<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Entity\PlannedMaintenance;
use App\State\PlannedTaskPatchProcessor;
use App\State\PlannedTaskProvider;
use Symfony\Component\Uid\Ulid;

/**
 * The dereferenceable read-model of a PlannedMaintenance task — one open item on the garage
 * checklist. Its ULID-keyed IRI (/garage/planned-tasks/{id}) is surfaced as `@id` so the dashboard
 * can tick it off: the PATCH flips `status` (the only writable field) to "done"/"dismissed". All
 * identity/content fields are read-only — the agent owns those through plan_maintenance.
 */
#[ApiResource(
    operations: [
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
    /** @var list<array{ref: string, label?: string, brand?: string, qty?: int}> */
    #[ApiProperty(writable: false, description: 'Parts/fluids the task needs — the shopping list.')]
    public array $parts = [];
    #[ApiProperty(description: 'Lifecycle: "open" | "done" | "dismissed". The only writable field — set "done" to tick the task off.')]
    public string $status;
    #[ApiProperty(writable: false, description: 'IRI of the vehicle this task belongs to.')]
    public string $vehicle;
    #[ApiProperty(writable: false)]
    public string $createdAt;
    #[ApiProperty(writable: false)]
    public ?string $completedAt = null;

    public static function fromEntity(PlannedMaintenance $task): self
    {
        $resource = new self();
        $resource->id = $task->getId();
        $resource->type = $task->getType()->value;
        $resource->label = $task->getLabel();
        $resource->target = null !== $task->getTargetUsageValue()
            ? ['value' => $task->getTargetUsageValue(), 'unit' => $task->getTargetUsageUnit()?->value]
            : null;
        $resource->dueDate = $task->getDueDate()?->format('Y-m-d');
        $resource->parts = $task->getParts();
        $resource->status = $task->getStatus()->value;
        $resource->vehicle = '/garage/vehicles/'.$task->getVehicle()->getId();
        $resource->createdAt = $task->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $resource->completedAt = $task->getCompletedAt()?->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
