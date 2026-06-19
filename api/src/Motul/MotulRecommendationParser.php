<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * Decodes Motul's dehydrated `__NEXT_DATA__` recommendation rows.
 *
 * The payload is normalised into a flat node pool at `props.pageProps.components`: every leaf is
 * stored once and referenced by its integer position (carried as a numeric STRING). A recommendation
 * is a dict of field→index; each index points at a `{title, value}` wrapper whose `value` is itself
 * an index onto the final string leaf. So a field resolves in two hops:
 *   row['viscosity'] = "382"  ->  components[382] = {title:…, value:"542"}  ->  components[542] = "5W-30".
 * Brittle by nature (it tracks Motul's SSR serialisation) — guarded by a fixture test so a format
 * shift fails loudly instead of silently yielding blank grades.
 */
final class MotulRecommendationParser
{
    private const ROW_KEYS = ['application', 'engineType', 'productType', 'quality', 'quantity', 'segment', 'sku', 'viscosity'];

    /**
     * @param array<string, mixed> $nextData decoded __NEXT_DATA__
     *
     * @return list<MotulOilRecommendation>
     */
    public function fromNextData(array $nextData): array
    {
        $pool = $nextData['props']['pageProps']['components'] ?? null;
        if (!\is_array($pool)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($pool as $node) {
            if (!\is_array($node) || array_diff(self::ROW_KEYS, array_keys($node))) {
                continue;
            }

            $rec = new MotulOilRecommendation(
                application: $this->resolve($pool, $node['application']),
                viscosity: $this->resolve($pool, $node['viscosity']),
                quality: $this->resolve($pool, $node['quality']),
                productType: $this->resolve($pool, $node['productType']),
                quantity: $this->resolve($pool, $node['quantity']),
                sku: $this->resolve($pool, $node['sku']),
            );

            $key = $rec->application.'|'.$rec->viscosity.'|'.$rec->quality.'|'.$rec->sku;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $rec;
        }

        return $out;
    }

    /**
     * @param list<mixed> $pool
     */
    private function resolve(array $pool, mixed $ref, int $depth = 0): string
    {
        if ($depth > 10) {
            return '';
        }

        if (\is_string($ref) && !is_numeric($ref)) {
            return $ref;
        }

        if (!is_numeric($ref)) {
            return '';
        }

        $node = $pool[(int) $ref] ?? null;
        if (\is_string($node)) {
            return $node;
        }
        if (\is_array($node)) {
            if (\array_key_exists('value', $node)) {
                return $this->resolve($pool, $node['value'], $depth + 1);
            }
            if (\array_key_exists('__str', $node)) {
                return (string) $node['__str'];
            }
        }

        return '';
    }
}
