<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A maintenance intent that maps to the catalog primitives: which part categories to pull
 * (path search terms).
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
     * confirming against a live getSearchTree(lang=6) — see the root TODO.md.
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
}
