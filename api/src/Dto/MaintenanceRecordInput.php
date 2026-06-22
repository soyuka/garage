<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The body of the dashboard's manual maintenance log — POST to create a past service, PATCH to edit
 * one. Lands as source "user". Cost is entered in major units (euros) and stored as integer cents.
 *
 * A visit carries one or more {@see $items} (each a service type with the parts it consumed). The
 * legacy single-{@see $type}/{@see $partsUsed} pair is still accepted for a one-service log; either
 * shape resolves to the canonical item list via {@see resolveItems()}.
 */
final class MaintenanceRecordInput
{
    /**
     * The visit's line-items — the canonical shape. One entry per service covered by the visit, each
     * {type, optional label, optional parts}. A vidange that changed oil AND the oil filter is ONE
     * visit with TWO items.
     *
     * @var list<array{type?: string, label?: string, parts?: list<array{ref?: string, brand?: string, label?: string, qty?: int, price?: float}>}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Service type, snake_case (oil_change, brakes…) or any descriptive free-text label for the long tail.'],
                'label' => ['type' => 'string', 'description' => 'Optional per-item label.'],
                'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'brand' => ['type' => 'string'], 'label' => ['type' => 'string'], 'qty' => ['type' => 'integer'], 'price' => ['type' => 'number', 'description' => 'Unit price in major units (12.50 for €12.50); stored as integer cents (priceCents) like the visit cost.']], 'required' => ['ref']]],
            ],
            'required' => ['type'],
        ],
        'description' => 'The services covered by this visit, each {type, optional parts}. Preferred over the single "type" field.',
    ])]
    public array $items = [];

    #[ApiProperty(description: 'Legacy single-service shorthand (snake_case type) — use "items" for a multi-service visit.')]
    public ?string $type = null;

    /**
     * @var list<array{ref?: string, brand?: string, label?: string, qty?: int}>
     */
    #[ApiProperty(description: 'Legacy: parts/fluids used, paired with the single "type" field.')]
    public array $partsUsed = [];

    #[ApiProperty(description: 'Date the service was performed (YYYY-MM-DD). Required on create; on edit, omit to keep the current date.')]
    public string $performedAt = '';

    #[ApiProperty(description: 'Optional human label, e.g. "vidange annuelle".')]
    public ?string $label = null;

    #[ApiProperty(description: 'Odometer/engine-hours reading at service time.')]
    public ?int $usageValue = null;

    #[ApiProperty(description: 'Unit of the reading: "km", "mi" or "h" (engine hours).')]
    public ?string $usageUnit = null;

    #[ApiProperty(description: 'Free-text notes about the work.')]
    public ?string $notes = null;

    #[ApiProperty(description: 'Cost in major units (e.g. 89.90 for €89.90).')]
    public ?float $cost = null;

    #[ApiProperty(description: 'ISO currency of the cost, e.g. "EUR". Defaults to EUR when a cost is given.')]
    public ?string $currency = null;

    #[ApiProperty(description: 'Who performed the work (garage name, "moi-même"…).')]
    public ?string $performedBy = null;

    /**
     * The canonical line-items for this visit: the explicit {@see $items} list when given, else the
     * legacy single-{@see $type} wrapped as one item. Blank/typeless entries are dropped — the caller
     * turns an empty result into a 422.
     *
     * Part prices stay in major units (the `price` key) here — {@see MaintenanceRecord::normaliseItems}
     * is the single chokepoint that converts them to the stored `priceCents`, shared with the MCP path.
     *
     * @return list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string, qty?: int, price?: float}>}>
     */
    public function resolveItems(): array
    {
        $raw = [] !== $this->items
            ? $this->items
            : (null !== $this->type ? [['type' => $this->type, 'parts' => $this->partsUsed]] : []);

        $resolved = [];
        foreach ($raw as $item) {
            $type = trim((string) ($item['type'] ?? ''));
            if ('' === $type) {
                continue;
            }

            $entry = ['type' => $type, 'parts' => array_values($item['parts'] ?? [])];
            if (isset($item['label']) && '' !== trim((string) $item['label'])) {
                $entry['label'] = trim((string) $item['label']);
            }
            $resolved[] = $entry;
        }

        return $resolved;
    }
}
