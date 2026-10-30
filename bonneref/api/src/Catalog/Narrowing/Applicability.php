<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\VehicleLinkCriteria;

/**
 * Where an article applies, kept verbatim. Never splits a cluster ({@see FingerprintBuilder} denies the breakpoints);
 * it only hints which of clusters that already differ suits one car.
 *
 * Under {@see ApplicabilityScope::Article} (partsapi) the criteria are the union over every vehicle the article fits,
 * so only the breakpoints (year, VIN/chassis, organisation number, serial) are kept, engine codes and model lists
 * being noise from other vehicles. Under {@see ApplicabilityScope::Vehicle} every qualifier of the article's link to
 * the car is one of its conditions on that car, so all are kept, per qualifier set.
 */
final class Applicability
{
    /** TecDoc "nur in Verbindung mit" / « uniquement en connexion avec »: the part it must be fitted with. */
    public const int PAIRING_CRITERION = 427;

    private const array KEYS = [
        '/^ann[ée]e /u',
        '/^(?:n[°º]? ?|num[ée]ro )de ch[âa]ssis/u',
        "/^(?:n[°º]?|№|num[ée]ro) ?d['’]organisation/u",
        '/^(?:pour )?(?:n[°º]?|№|num[ée]ro) ?opr/u',
        '/^(?:n[°º]? ?|№ ?|num[ée]ro )de s[ée]rie/u',
    ];

    /**
     * @param list<ArticleCriterion> $criteria
     *
     * @return array<string, list<string>> verbatim criterion name => verbatim values
     */
    public static function fromCriteria(array $criteria): array
    {
        $found = [];
        foreach ($criteria as $criterion) {
            $name = trim($criterion->name);
            if ('' === $name || !self::isApplicationKey(mb_strtolower($name))) {
                continue;
            }
            foreach (explode(';', $criterion->value) as $value) {
                $value = trim($value);
                if ('' !== $value) {
                    $found[$name][$value] = true;
                }
            }
        }

        return array_map(static fn (array $values): array => array_map('strval', array_keys($values)), $found);
    }

    /**
     * @param list<array<string, list<string>>> $sets best member first
     *
     * @return list<array{name: string, values: list<string>}>
     */
    public static function union(array $sets): array
    {
        $union = [];
        foreach ($sets as $set) {
            foreach ($set as $name => $values) {
                foreach ($values as $value) {
                    $union[$name][$value] = true;
                }
            }
        }

        $out = [];
        foreach ($union as $name => $values) {
            $out[] = ['name' => (string) $name, 'values' => array_map('strval', array_keys($values))];
        }

        return $out;
    }

    /**
     * One entry per qualifier set, any of which may hold; empty when the link carries none.
     *
     * @return list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}>
     */
    public static function ofLink(VehicleLinkCriteria $link): array
    {
        $alternatives = [];
        foreach ($link->qualifierSets as $set) {
            $conditions = [];
            $pairedWith = [];
            foreach ($set as $criterion) {
                if (self::PAIRING_CRITERION === $criterion->id) {
                    $pairedWith[$criterion->value] = true;
                    continue;
                }
                $key = $criterion->id ?? $criterion->name;
                $conditions[$key]['name'] ??= $criterion->name;
                $conditions[$key]['values'][$criterion->value] = true;
            }
            $alternatives[] = [
                'conditions' => array_values(array_map(static fn (array $c): array => ['name' => $c['name'], 'values' => array_map('strval', array_keys($c['values']))], $conditions)),
                'pairedWith' => array_map('strval', array_keys($pairedWith)),
            ];
        }

        return $alternatives;
    }

    /**
     * The conditions every variant holds in every one of its alternatives: what a cluster can state about the car
     * without lending one article's condition to another. A variant with an unconditional link shares nothing.
     *
     * @param list<list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}>> $variants {@see self::ofLink()} of each variant
     *
     * @return list<array{name: string, values: list<string>}>
     */
    public static function shared(array $variants): array
    {
        $common = null;
        foreach ($variants as $alternatives) {
            $held = null;
            foreach ($alternatives as $alternative) {
                $pairs = [];
                foreach ($alternative['conditions'] as $condition) {
                    foreach ($condition['values'] as $value) {
                        $pairs[$condition['name']."\0".$value] = [$condition['name'], $value];
                    }
                }
                $held = null === $held ? $pairs : array_intersect_key($held, $pairs);
            }
            $held ??= [];
            $common = null === $common ? $held : array_intersect_key($common, $held);
        }

        $out = [];
        foreach ($common ?? [] as [$name, $value]) {
            $out[$name][] = $value;
        }

        return array_map(static fn (string $name, array $values): array => ['name' => $name, 'values' => $values], array_keys($out), array_values($out));
    }

    /**
     * @param list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}> $alternatives see {@see self::ofLink()}
     */
    public static function describe(array $alternatives): string
    {
        $lines = [];
        foreach ($alternatives as $alternative) {
            $parts = array_map(static fn (array $c): string => $c['name'].' : '.implode(', ', $c['values']), $alternative['conditions']);
            if ([] !== $alternative['pairedWith']) {
                $parts[] = 'only with '.implode(', ', $alternative['pairedWith']);
            }
            $lines[] = [] === $parts ? 'no condition' : implode(', ', $parts);
        }

        return [] === $lines ? 'no condition' : implode(' OR ', $lines);
    }

    private static function isApplicationKey(string $lowerName): bool
    {
        foreach (self::KEYS as $pattern) {
            if (1 === preg_match($pattern, $lowerName)) {
                return true;
            }
        }

        return false;
    }
}
