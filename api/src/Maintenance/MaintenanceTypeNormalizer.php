<?php

declare(strict_types=1);

namespace App\Maintenance;

/**
 * Canonicalises a service-item `type` on write. The log accepts any descriptive type (the long tail
 * is deliberate — a service we never planned must still be loggable), but a KNOWN service entered
 * under a label or synonym ("Huile", "Vidange", "bougies") must land on its canonical snake_case key,
 * because the schedule + forecast match baselines on that exact string. So this maps recognised
 * aliases → canonical; anything unrecognised is returned verbatim (only surrounding whitespace
 * trimmed), never rejected and never collapsed to "other".
 */
final class MaintenanceTypeNormalizer
{
    /**
     * Recognised aliases → canonical snake_case type. Keys are pre-folded (lowercased, accents
     * stripped, whitespace collapsed) so a match is robust to case/accents/spacing. The canonical
     * values are the documented vocabulary, not only {@see MaintenanceType} — the forecast keys on
     * the string, so the agreed snake_case spelling is what matters, enum or not.
     */
    private const array ALIASES = [
        // oil change / vidange
        'huile' => 'oil_change',
        'huile moteur' => 'oil_change',
        'vidange' => 'oil_change',
        'vidange moteur' => 'oil_change',
        'oil' => 'oil_change',
        'oil change' => 'oil_change',
        'engine oil' => 'oil_change',
        // oil filter
        'filtre a huile' => 'oil_filter',
        'filtre huile' => 'oil_filter',
        'oil filter' => 'oil_filter',
        // air filter
        'filtre a air' => 'air_filter',
        'filtre air' => 'air_filter',
        'air filter' => 'air_filter',
        'prefiltre' => 'air_filter',
        // fuel filter
        'filtre a carburant' => 'fuel_filter',
        'filtre carburant' => 'fuel_filter',
        'filtre a essence' => 'fuel_filter',
        'filtre essence' => 'fuel_filter',
        'fuel filter' => 'fuel_filter',
        // cabin filter
        "filtre d'habitacle" => 'cabin_filter',
        'filtre habitacle' => 'cabin_filter',
        'filtre pollen' => 'cabin_filter',
        'cabin filter' => 'cabin_filter',
        // spark plug
        'bougie' => 'spark_plug',
        'bougies' => 'spark_plug',
        "bougie d'allumage" => 'spark_plug',
        "bougies d'allumage" => 'spark_plug',
        'spark plug' => 'spark_plug',
        'spark plugs' => 'spark_plug',
        // tires
        'pneu' => 'tires',
        'pneus' => 'tires',
        'tire' => 'tires',
        'tires' => 'tires',
        'tyre' => 'tires',
        'tyres' => 'tires',
        // brakes
        'frein' => 'brakes',
        'freins' => 'brakes',
        'plaquettes' => 'brakes',
        'plaquettes de frein' => 'brakes',
        'brake' => 'brakes',
        'brakes' => 'brakes',
        // coolant
        'liquide de refroidissement' => 'coolant',
        'refroidissement' => 'coolant',
        'antigel' => 'coolant',
        'coolant' => 'coolant',
        // brake fluid
        'liquide de frein' => 'brake_fluid',
        'brake fluid' => 'brake_fluid',
        // timing belt
        'courroie de distribution' => 'timing_belt',
        'distribution' => 'timing_belt',
        'timing belt' => 'timing_belt',
        // gearbox oil
        'huile de boite' => 'gearbox_oil',
        'huile boite' => 'gearbox_oil',
        'gearbox oil' => 'gearbox_oil',
        // battery
        'batterie' => 'battery',
        'battery' => 'battery',
        // inspection / contrôle technique
        'controle technique' => 'inspection',
        'controle' => 'inspection',
        'ct' => 'inspection',
        'inspection' => 'inspection',
        // repair
        'reparation' => 'repair',
        'repair' => 'repair',
    ];

    /** Map one type to its canonical key, or return it verbatim (trimmed) when unrecognised. */
    public function canonical(string $type): string
    {
        $trimmed = trim($type);

        return self::ALIASES[$this->fold($trimmed)] ?? $trimmed;
    }

    /**
     * Canonicalise every item's `type` in place, leaving parts/label and any other key untouched.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeItems(array $items): array
    {
        return array_map(function (array $item): array {
            if (isset($item['type']) && \is_string($item['type'])) {
                $item['type'] = $this->canonical($item['type']);
            }

            return $item;
        }, $items);
    }

    /** Fold to the lookup form: lowercase, accents stripped, internal whitespace collapsed. */
    private function fold(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');
        $ascii = strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }
}
