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
     * Case-insensitive path search terms for bonneref's part-category search. Both the French and the
     * English TecDoc group names are listed because bonneref's catalogue language (CATALOG_LANG)
     * decides which one the path carries.
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
