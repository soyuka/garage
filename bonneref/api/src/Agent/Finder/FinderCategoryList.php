<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\ApiResource\PartCategory;
use App\Catalog\CarType;
use App\Catalog\PartsCatalog;
use App\Exception\MissingApiKeyException;
use App\Exception\UpstreamException;

/**
 * Every leaf category of one vehicle's tree, for the finder agent to pick by meaning instead of by
 * keyword. One line per leaf under a `[parent breadcrumb]` header: the full path repeated on each
 * line costs ~40% more tokens on the 307 tree (596 leaves).
 */
final readonly class FinderCategoryList
{
    /** Workshop tools, never a part of the car (307 « Outils spéciaux > … > Rotules » holds GEDORE wrenches). */
    private const string TOOLS_BRANCH = 'Outils spéciaux';

    private const array NON_PART_LEAVES = [
        'Outils',
        "Recherche d'un article par graphique",
        'Graphique complet des pièces de carrosserie',
    ];

    public function __construct(private PartsCatalog $catalog)
    {
    }

    public function forVehicle(int $carId, CarType $carType): string
    {
        try {
            $leaves = $this->catalog->partCategories($carId, $carType);
        } catch (UpstreamException|MissingApiKeyException) {
            return 'Liste des catégories indisponible (catalogue injoignable).';
        }

        return self::format($leaves);
    }

    /**
     * @param list<PartCategory> $leaves
     */
    public static function format(array $leaves): string
    {
        $byParent = [];
        foreach ($leaves as $leaf) {
            $segments = array_map(trim(...), explode('>', $leaf->path));
            if (self::TOOLS_BRANCH === $segments[0] || \in_array(end($segments), self::NON_PART_LEAVES, true)) {
                continue;
            }
            $name = (string) array_pop($segments);
            $byParent[implode(' > ', $segments)][] = [$leaf->id, $name];
        }
        ksort($byParent, \SORT_STRING);

        $lines = [];
        foreach ($byParent as $parent => $entries) {
            if ('' !== $parent) {
                $lines[] = '['.$parent.']';
            }
            usort($entries, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
            foreach ($entries as [$id, $name]) {
                $lines[] = $id.' '.$name;
            }
        }

        return implode("\n", $lines);
    }
}
