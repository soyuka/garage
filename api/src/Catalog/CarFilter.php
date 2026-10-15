<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Car;

/**
 * Server-side narrowing of a model's cached getCars rows, so the agent filters instead of paging.
 */
final class CarFilter
{
    private const LITRE_TOLERANCE = 0.05;

    /**
     * @param list<Car> $cars
     *
     * @return list<Car>
     */
    public static function apply(array $cars, ?int $year = null, ?float $capacityLitres = null, ?string $fuel = null, ?string $search = null): array
    {
        $fuel = null !== $fuel && '' !== trim($fuel) ? self::normalizeFuel($fuel) : null;
        $search = null !== $search && '' !== trim($search) ? trim($search) : null;

        return array_values(array_filter(
            $cars,
            static fn (Car $car): bool => (null === $year || self::producedIn($car, $year))
                && (null === $capacityLitres || self::hasCapacity($car, $capacityLitres))
                && (null === $fuel || self::fuelOf($car) === $fuel)
                && (null === $search || false !== mb_stripos($car->name, $search) || false !== mb_stripos((string) $car->fullName, $search)),
        ));
    }

    private static function producedIn(Car $car, int $year): bool
    {
        $from = null !== $car->yearFrom ? (int) substr($car->yearFrom, 0, 4) : null;
        $to = null !== $car->yearTo ? (int) substr($car->yearTo, 0, 4) : null;

        return (null === $from || $from <= $year) && (null === $to || $year <= $to);
    }

    /**
     * Catalogue CAPACITY is "cm3/litres" ("1999/2.0 l"); the litre part is cm3 rounded to one decimal.
     */
    private static function hasCapacity(Car $car, float $litres): bool
    {
        return null !== $car->capacityCc && abs(round($car->capacityCc / 1000, 1) - $litres) <= self::LITRE_TOLERANCE + 1e-9;
    }

    private static function fuelOf(Car $car): ?string
    {
        $type = mb_strtolower((string) $car->engineType);

        return match (true) {
            '' === $type => null,
            str_contains($type, 'hybrid') => 'hybride',
            str_contains($type, 'lpg') || str_contains($type, 'gpl') => 'gpl',
            str_contains($type, 'electr') => 'electrique',
            str_contains($type, 'diesel') => 'diesel',
            str_contains($type, 'petrol') || str_contains($type, 'gasoline') || str_contains($type, 'essence') => 'essence',
            default => null,
        };
    }

    private static function normalizeFuel(string $fuel): string
    {
        return strtr(mb_strtolower(trim($fuel)), ['é' => 'e', 'è' => 'e']);
    }
}
