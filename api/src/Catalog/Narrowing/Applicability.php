<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;

/**
 * The application-level breakpoints of an article (year, VIN/chassis, organisation number, serial),
 * kept verbatim. They are per ARTICLE — the union over every vehicle it fits — so they never split a
 * cluster ({@see FingerprintBuilder} denies them); they only hint which of clusters that already
 * differ suits one car. Engine codes, model lists and TecDoc engine numbers are left out as noise.
 */
final class Applicability
{
    public const string SCOPE = 'article';

    private const array KEYS = [
        '/^ann[ée]e /u',
        '/^num[ée]ro de ch[âa]ssis/u',
        "/^(?:n[°º]?|№|num[ée]ro) ?d['’]organisation/u",
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
