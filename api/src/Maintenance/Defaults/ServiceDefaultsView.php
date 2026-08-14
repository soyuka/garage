<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

use App\Garage\VehicleType;

/**
 * Renders baselines for the `list_service_defaults` tool result. Provenance travels with every row —
 * source, its URL when there is one, an explicit `unsourced` flag and the review date — so the model
 * can caveat a trade-consensus figure instead of quoting it as manufacturer data.
 *
 * `hasInterval: false` is spelled out rather than left to be inferred from two nulls: "this part has
 * NO replacement interval" is a finding, and a model reading only the null fields would treat it as
 * missing data and go looking for a number.
 */
final readonly class ServiceDefaultsView
{
    /**
     * @return array<string, mixed>
     */
    public function baseline(ServiceBaseline $baseline): array
    {
        return [
            'key' => $baseline->key(),
            'type' => $baseline->type,
            'action' => $baseline->action->value,
            'appliesWhen' => null === $baseline->discriminantKey ? null : [
                'fact' => $baseline->discriminantKey,
                'values' => $baseline->discriminantValues,
            ],
            'segments' => array_map(static fn (VehicleType $t): string => $t->value, $baseline->segments),
            'hasInterval' => $baseline->hasCadence(),
            'everyUsageValue' => $baseline->everyUsageValue,
            'everyUsageUnit' => $baseline->everyUsageUnit?->value,
            'everyMonths' => $baseline->everyMonths,
            'source' => $baseline->source,
            'sourceUrl' => $baseline->sourceUrl,
            'unsourced' => null === $baseline->sourceUrl,
            'reviewedOn' => $baseline->reviewedOn,
            'note' => $baseline->note,
        ];
    }

    /**
     * @param list<ServiceBaseline> $baselines
     *
     * @return list<array<string, mixed>>
     */
    public function baselines(array $baselines): array
    {
        return array_map($this->baseline(...), $baselines);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolution(ServiceDefaultsResolution $resolution): array
    {
        $baseline = $resolution->baseline();

        return [
            'status' => $resolution->status->value,
            'type' => $resolution->type,
            'discriminantKey' => $resolution->discriminantKey,
            'discriminantValue' => $resolution->discriminantValue,
            'reason' => $resolution->reason,
            'baseline' => null === $baseline ? null : $this->baseline($baseline),
            'candidates' => $this->baselines($resolution->candidates),
        ];
    }
}
