<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;

/**
 * Flattens a MaintenanceRecord into the array the MCP tools surface — mirrors
 * App\Garage\FactView. Money is reassembled from integer cents into a {amount, currency}
 * decimal string so the LLM never sees raw cents; usage into {value, unit}.
 */
final class RecordView
{
    /**
     * @return array{
     *     type: string,
     *     label: string|null,
     *     performedAt: string,
     *     usage: array{value: int, unit: string|null}|null,
     *     cost: array{amount: string, currency: string|null}|null,
     *     notes: string|null,
     *     performedBy: string|null,
     *     partsUsed: list<array{ref: string, label?: string}>,
     *     source: string,
     *     addedAt: string
     * }
     */
    public static function toArray(MaintenanceRecord $record): array
    {
        $usageValue = $record->getUsageValue();
        $costCents = $record->getCostCents();

        return [
            'type' => $record->getType()->value,
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
            'partsUsed' => $record->getPartsUsed(),
            'source' => $record->getSource(),
            'addedAt' => $record->getAddedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
