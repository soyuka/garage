<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * Decodes Motul's dehydrated `__NEXT_DATA__` recommendation tree.
 *
 * The payload is normalised into a flat node pool at `props.pageProps.components`: every leaf is
 * stored once and referenced by its integer position (carried as a numeric STRING). Each fluid
 * category is a `{capacities, category, conditions, recommendations}` node; resolving it (following
 * those index references, unwrapping `{title,value}` / `{__str}` leaves) yields the readable tree.
 * Brittle by nature — it tracks Motul's SSR serialisation — so it is guarded by a fixture test: a
 * format shift fails loudly instead of silently yielding blank grades.
 */
final class MotulRecommendationParser
{
    private const CATEGORY_KEYS = ['capacities', 'category', 'conditions', 'recommendations'];
    private const MAX_DEPTH = 24;

    /**
     * @param array<array-key, mixed> $nextData decoded __NEXT_DATA__
     *
     * @return list<MotulCategory>
     */
    public function fromNextData(array $nextData): array
    {
        $pool = $nextData['props']['pageProps']['components'] ?? null;
        if (!\is_array($pool)) {
            return [];
        }

        $out = [];
        foreach ($pool as $node) {
            if (!\is_array($node) || [] !== array_diff(self::CATEGORY_KEYS, array_keys($node))) {
                continue;
            }

            $cat = $this->resolve($pool, $node);
            if (!\is_array($cat)) {
                continue;
            }

            $out[] = $this->toCategory($cat);
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $cat a fully-resolved category node
     */
    private function toCategory(array $cat): MotulCategory
    {
        $conditions = [];
        $aceaStandards = [];

        foreach (\is_array($cat['recommendations'] ?? null) ? $cat['recommendations'] : [] as $rec) {
            if (!\is_array($rec)) {
                continue;
            }

            $cond = \is_array($rec['conditions'] ?? null) ? $rec['conditions'] : [];
            $viscosities = [];
            $interval = null;

            foreach (\is_array($rec['products'] ?? null) ? $rec['products'] : [] as $product) {
                if (!\is_array($product)) {
                    continue;
                }

                $name = $this->scalar($product['name'] ?? null, 'name');
                if (null !== ($grade = self::viscosity($name))) {
                    $viscosities[$grade] = true;
                }

                $normalized = \is_array($product['details']['normalizedData'] ?? null) ? $product['details']['normalizedData'] : [];
                foreach (['aceaStandards', 'apiStandards'] as $field) {
                    $std = $this->scalar($normalized[$field] ?? null, $field);
                    if (null !== $std && '' !== $std) {
                        $aceaStandards[$std] = true;
                    }
                }

                $interval ??= $this->productInterval($product);
            }

            $conditions[] = new MotulConditionGrade(
                usage: $this->scalar($cond['usage'] ?? null, 'usage') ?? '',
                mileage: $this->scalar($cond['mileage'] ?? null, 'mileage') ?? '',
                interval: $interval,
                viscosities: array_keys($viscosities),
            );
        }

        $capacities = [];
        foreach (\is_array($cat['capacities'] ?? null) ? $cat['capacities'] : [] as $cap) {
            $label = match (true) {
                \is_array($cap) => $this->scalar($cap['label'] ?? null, 'label'),
                \is_string($cap) => $cap,
                default => null,
            };
            if (null !== $label && '' !== $label) {
                $capacities[] = $label;
            }
        }

        return new MotulCategory(
            name: $this->scalar($cat['category']['name'] ?? null, 'name') ?? '',
            capacities: $capacities,
            aceaStandards: array_keys($aceaStandards),
            conditions: $conditions,
        );
    }

    /**
     * @param array<array-key, mixed> $product
     */
    private function productInterval(array $product): ?string
    {
        $comments = $product['apporder']['comments'] ?? null;
        if (!\is_array($comments)) {
            return null;
        }

        foreach ($comments as $comment) {
            $label = \is_array($comment) ? $this->scalar($comment['label'] ?? null, 'label') : null;
            if (null !== $label && '' !== $label) {
                return $label;
            }
        }

        return null;
    }

    private static function viscosity(?string $name): ?string
    {
        if (null !== $name && preg_match('/\d+W-?\d+/', $name, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Some leaves resolve to a single-key wrapper {field: "value"} (e.g. {"name":"…"}); unwrap that.
     */
    private function scalar(mixed $value, string $field): ?string
    {
        if (\is_array($value)) {
            $value = $value[$field] ?? null;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $pool
     */
    private function resolve(array $pool, mixed $ref, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if (is_numeric($ref)) {
            return $this->resolve($pool, $pool[(int) $ref] ?? null, $depth + 1);
        }

        if (\is_array($ref)) {
            if (['title', 'value'] === array_keys($ref)) {
                return $this->resolve($pool, $ref['value'], $depth + 1);
            }
            if (['__str'] === array_keys($ref)) {
                return (string) $ref['__str'];
            }

            $resolved = [];
            foreach ($ref as $key => $value) {
                $resolved[$key] = $this->resolve($pool, $value, $depth + 1);
            }

            return $resolved;
        }

        return $ref;
    }
}
