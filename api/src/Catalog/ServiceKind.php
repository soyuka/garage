<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A maintenance intent that maps to the catalog primitives: which part categories to pull
 * (path search terms) and which fluid specs are relevant (fill-volume label substrings).
 * Drives the get_service_parts tool so a single call answers "what do I need for this service".
 */
enum ServiceKind: string
{
    case OilChange = 'oil_change';
    case Filters = 'filters';
    case Brakes = 'brakes';
    case SparkPlugs = 'spark_plugs';

    /**
     * Case-insensitive path search terms fed to list_part_categories. Both the French and the
     * English TecDoc group names are listed: the active partsapi language (PARTSAPI_LANG, default
     * 6 = Français) decides which one matches the STR_PATH, so the search works in either language
     * and stays resilient if the language is changed. The exact French strings still need
     * confirming against a live getSearchTree(lang=6) — see docs/TODO.md.
     *
     * @return list<string>
     */
    public function categorySearches(): array
    {
        return match ($this) {
            self::OilChange => ['filtre à huile', 'oil filter'],
            self::Filters => ['filtre', 'filter'],
            self::Brakes => ['frein', 'brake'],
            self::SparkPlugs => ['bougie', 'spark plug'],
        };
    }

    /**
     * Case-insensitive fill-volume label substrings to surface as relevant fluids.
     *
     * @return list<string>
     */
    public function fluidLabels(): array
    {
        return match ($this) {
            self::OilChange => ['huile moteur'],
            self::Brakes => ['frein'],
            self::Filters, self::SparkPlugs => [],
        };
    }
}
