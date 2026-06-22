<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Input for the `edit_maintenance` MCP tool: a SPARSE in-place edit of one already-logged visit,
 * addressed by its @id. Only the fields the caller supplies are changed; everything omitted is left
 * exactly as it was (unlike the dashboard's full-replace PATCH). The companion to log_maintenance —
 * use it to fix a visit (correct a wrong cost, attach the missing bill amount, fix the date), never
 * to record a new one.
 */
final class EditMaintenanceInput
{
    #[ApiProperty(description: 'REQUIRED. The @id of the maintenance record to edit — the IRI surfaced by get_maintenance_history (e.g. "/garage/maintenance-records/01J…"). The visit at that IRI is mutated in place; its identity (@id) is preserved.')]
    public ?string $record = null;

    /**
     * @var list<array{type?: string, label?: string, parts?: list<array{ref?: string, brand?: string, label?: string, price?: float}>}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Service type, snake_case (oil_change, oil_filter…) or any descriptive label.'],
                'label' => ['type' => 'string', 'description' => 'Optional per-item label.'],
                'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'brand' => ['type' => 'string'], 'label' => ['type' => 'string'], 'price' => ['type' => 'number', 'description' => 'Unit price in major units (12.50 for €12.50); stored as integer cents.']], 'required' => ['ref']]],
            ],
            'required' => ['type'],
        ],
        'description' => 'Replace the visit\'s line-items wholesale (one entry per service, each {type, optional parts}). Omit to keep the current items — supplying an empty array does NOT clear them.',
    ])]
    public array $items = [];

    #[ApiProperty(description: 'New visit label, e.g. "révision annuelle". Omit to leave unchanged.')]
    public ?string $label = null;

    #[ApiProperty(description: 'New service date, ISO 8601 (e.g. "2026-06-15"). Omit to leave unchanged.')]
    public ?string $performedAt = null;

    #[ApiProperty(description: 'New odometer/hour-meter reading at service time. Omit to leave unchanged. NOTE: editing this corrects the record only — it does not move the vehicle\'s current reading.')]
    public ?int $usageValue = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['km', 'mi', 'hours'], 'description' => 'Unit of usageValue ("hours" for engine-hour machinery). Omit to leave unchanged.'])]
    public ?string $usageUnit = null;

    #[ApiProperty(description: 'New free-text notes. Omit to leave unchanged.')]
    public ?string $notes = null;

    #[ApiProperty(description: 'New cost as a decimal string, e.g. "149.90". Pair with currency. Omit to leave unchanged.')]
    public ?string $cost = null;

    #[ApiProperty(description: 'ISO 4217 currency for cost, e.g. "EUR". Defaults to EUR when a cost is given.')]
    public ?string $currency = null;

    #[ApiProperty(description: 'Who did the work: "self" or the garage name. Omit to leave unchanged.')]
    public ?string $performedBy = null;
}
