<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\PlannedTaskPartDeleteProcessor;
use App\State\PlannedTaskPartProcessor;
use App\State\PlannedTaskPartProvider;
use Symfony\Component\Uid\Ulid;

/**
 * One editable line on a {@see PlannedTask}'s shopping list, addressed as a subresource so the
 * dashboard can add/edit/remove a single part without re-sending the whole list. POST appends (upsert
 * by ref), PATCH merge-edits one line by its ULID, DELETE drops it. The derived fluid `spec` is NOT
 * exposed here — it lives only in the task read where the vehicle facts are in scope.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/garage/planned-tasks/{taskId}/parts',
            uriVariables: [
                'taskId' => new Link(fromClass: PlannedTask::class, identifiers: ['id']),
            ],
            status: 201,
            processor: PlannedTaskPartProcessor::class,
        ),
        new Patch(
            uriTemplate: '/garage/planned-tasks/{taskId}/parts/{id}',
            uriVariables: [
                'taskId' => new Link(fromClass: PlannedTask::class, identifiers: ['id']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            provider: PlannedTaskPartProvider::class,
            processor: PlannedTaskPartProcessor::class,
        ),
        new Delete(
            uriTemplate: '/garage/planned-tasks/{taskId}/parts/{id}',
            uriVariables: [
                'taskId' => new Link(fromClass: PlannedTask::class, identifiers: ['id']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            read: false,
            processor: PlannedTaskPartDeleteProcessor::class,
        ),
    ],
)]
final class PlannedTaskPart
{
    #[ApiProperty(identifier: true, description: 'Stable ULID of the part line (the edit/remove handle).')]
    public string $id;

    #[ApiProperty(writable: false, description: 'IRI of the owning planned task.')]
    public string $task;

    #[ApiProperty(description: 'Catalog part number; empty for a bare fluid line.')]
    public string $ref = '';
    public ?string $label = null;
    public ?string $brand = null;
    public ?int $qty = null;
    #[ApiProperty(description: 'Unit price, integer minor units (cents).')]
    public ?int $priceCents = null;
    #[ApiProperty(description: 'Where the part is bought, free text (e.g. "trodo.fr").')]
    public ?string $supplier = null;

    /**
     * @param array{id: string, ref: string, label?: string, brand?: string, qty?: int, priceCents?: int, supplier?: string, findRoute?: string} $part
     */
    public static function fromArray(array $part, Ulid $taskId): self
    {
        $resource = new self();
        $resource->id = $part['id'];
        $resource->task = '/garage/planned-tasks/'.(string) $taskId;
        $resource->ref = $part['ref'];
        $resource->label = $part['label'] ?? null;
        $resource->brand = $part['brand'] ?? null;
        $resource->qty = $part['qty'] ?? null;
        $resource->priceCents = $part['priceCents'] ?? null;
        $resource->supplier = $part['supplier'] ?? null;

        return $resource;
    }
}
