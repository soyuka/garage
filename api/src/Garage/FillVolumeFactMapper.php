<?php

declare(strict_types=1);

namespace App\Garage;

use App\ApiResource\FillVolume;

/**
 * Maps AUTONORMS GetFillVolumes rows onto stable garage fact keys.
 *
 * Keyed by the upstream Russian `originalTitle` (the only language-stable identifier —
 * the French labels are presentation-only). Unmapped rows are dropped: a fill-volume
 * with no canonical key would pollute the store with ad-hoc keys the LLM can't query for.
 *
 * Upstream may list several values under one title (e.g. "API GL4" + "ZF TE-ML 02D" for the
 * gearbox oil spec); they collapse to a single fact (one per key — UNIQUE(vehicle_id,
 * fact_key)), values merged so none is silently dropped.
 */
final class FillVolumeFactMapper
{
    private const KEY_BY_TITLE = [
        'Моторное масло с фильтром' => 'oil_capacity',
        'Спецификация моторного масла' => 'oil_type',
        'Масло коробки переключения передач' => 'gearbox_oil_capacity',
        'Спецификация масла ступенчатой коробки передач' => 'gearbox_oil_type',
        'Масло для автоматической коробки переключения передач' => 'auto_gearbox_oil_capacity',
        'Спецификация масла автоматической коробки передач' => 'auto_gearbox_oil_type',
        'Масло дифференциала заднего моста' => 'rear_diff_oil_capacity',
        'Спецификация масла заднего моста' => 'rear_diff_oil_type',
        'Масло вспомогания системы рулевого управления' => 'power_steering_oil_capacity',
        'Спецификация масла гидроусилителя руля' => 'power_steering_oil_type',
        'Охлаждающая жидкость' => 'coolant_capacity',
        'Спецификация охлаждающей жидкости' => 'coolant_type',
        'Тормозная жидкость при замене' => 'brake_fluid_capacity',
        'Спецификация тормозной жидкости' => 'brake_fluid_type',
        'Спецификация жидкости сцепления' => 'clutch_fluid_type',
        'Хладагент для кондиционера' => 'ac_refrigerant_capacity',
        'Спецификация хладагента' => 'ac_refrigerant_type',
        'Масло кондиционер' => 'ac_oil_capacity',
        'Спецификация масла кондиционерa' => 'ac_oil_type',
        'Ёмкость бака' => 'fuel_tank_capacity',
    ];

    /**
     * @param list<FillVolume> $fillVolumes
     *
     * @return list<array{key: string, value: string, unit: string|null}>
     */
    public function toFacts(array $fillVolumes): array
    {
        /** @var array<string, array{key: string, value: string, unit: string|null}> $byKey */
        $byKey = [];
        foreach ($fillVolumes as $fillVolume) {
            $key = self::KEY_BY_TITLE[$fillVolume->originalTitle] ?? null;
            if (null === $key) {
                continue;
            }

            $value = null !== $fillVolume->volume
                ? rtrim(rtrim(sprintf('%.2f', $fillVolume->volume), '0'), '.')
                : (string) ($fillVolume->info ?? '');

            if ('' === $value) {
                continue;
            }

            if (isset($byKey[$key])) {
                $byKey[$key]['value'] .= ' | '.$value;
                continue;
            }

            $byKey[$key] = ['key' => $key, 'value' => $value, 'unit' => $fillVolume->unit];
        }

        return array_values($byKey);
    }
}
