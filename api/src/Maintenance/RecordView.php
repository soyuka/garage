<?php

declare(strict_types=1);

namespace App\Maintenance;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Entity\MaintenanceRecord;

/**
 * Flattens a MaintenanceRecord into the array the MCP tools surface — mirrors
 * App\Garage\FactView. Money is reassembled from integer cents into a {amount, currency}
 * decimal string so the LLM never sees raw cents; usage into {value, unit}. The `@id` is the
 * dereferenceable GarageMaintenanceRecord IRI.
 */
final class RecordView
{
    public function __construct(private readonly IriConverterInterface $iriConverter)
    {
    }

    /**
     * @return array{
     *     @id: string,
     *     items: list<array{type: string, label?: string, parts: list<array{ref: string, brand?: string, label?: string, qty?: int, priceCents?: int}>}>,
     *     label: string|null,
     *     performedAt: string,
     *     usage: array{value: int, unit: string|null}|null,
     *     cost: array{amount: string, currency: string|null}|null,
     *     notes: string|null,
     *     performedBy: string|null,
     *     source: string,
     *     addedAt: string
     * }
     */
    public function toArray(MaintenanceRecord $record): array
    {
        $usageValue = $record->getUsageValue();
        $costCents = $record->getCostCents();

        return [
            '@id' => $this->iriConverter->getIriFromResource(GarageMaintenanceRecord::fromEntity($record)),
            'items' => $record->getItems(),
            'label' => $record->getLabel(),
            'performedAt' => $record->getPerformedAt()->format('Y-m-d'),
            'usage' => null !== $usageValue
                ? ['value' => $usageValue, 'unit' => $record->getUsageUnit()?->value]
                : null,
            'cost' => null !== $costCents
                ? ['amount' => number_format($costCents / 100, 2, '.', ''), 'currency' => $record->getCurrency()]
                : null,
            'notes' => $record->getNotes(),
            'performedBy' => $record->getPerformedBy(),
            'source' => $record->getSource(),
            'addedAt' => $record->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
