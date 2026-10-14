<?php

declare(strict_types=1);

namespace App\Maintenance;

final class ConsumableStandard
{
    /**
     * Canonical ref => ['aliases' => pure spelling variants, 'products' => product/brand names].
     * Both are compared in squashed form (see squash()), so case, spaces, hyphens and accents never
     * need listing. A product alias resolves to the same standard but its original wording is kept
     * on the line's label (see resolve()). Adding a standard is one line here.
     *
     * @var array<string, array{aliases?: list<string>, products?: list<string>}>
     */
    private const array STANDARDS = [
        'DOT 3' => [],
        'DOT 4' => [],
        'DOT 5.1' => [],
        'ATF Dexron II D' => ['aliases' => ['dexron ii', 'dexron iid', 'atf dexron ii']],
        'ATF Dexron III' => ['aliases' => ['dexron iii', 'dexron iiih']],
        'frein-filet moyen' => ['aliases' => ['frein filet bleu', 'threadlocker medium'], 'products' => ['loctite 243']],
        'frein-filet fort' => ['aliases' => ['frein filet rouge', 'threadlocker strong'], 'products' => ['loctite 270']],
        'graisse Li-MoS2 NLGI 2' => ['aliases' => ['graisse limos2', 'graisse au bisulfure de molybdène']],
        'silicone RTV haute température' => ['aliases' => ['silicone rtv haute temp', 'joint silicone haute température']],
    ];

    private const string SAE_MULTIGRADE = '/^(?:sae)?(\d{1,2})w(\d{2,3})$/';
    private const string SAE_WINTER_MONOGRADE = '/^(?:sae)?(\d{2,3})w$/';
    private const string SAE_HOT_MONOGRADE = '/^sae(\d{2,3})$/';

    public static function normalise(string $ref): string
    {
        $key = self::squash($ref);
        if ('' === $key) {
            return $ref;
        }

        $canonical = self::index()[$key] ?? null;
        if (null !== $canonical) {
            return $canonical;
        }

        if (1 === preg_match(self::SAE_MULTIGRADE, $key, $m)) {
            return \sprintf('SAE %dW-%d', (int) $m[1], (int) $m[2]);
        }
        if (1 === preg_match(self::SAE_WINTER_MONOGRADE, $key, $m)) {
            return \sprintf('SAE %dW', (int) $m[1]);
        }
        if (1 === preg_match(self::SAE_HOT_MONOGRADE, $key, $m)) {
            return \sprintf('SAE %d', (int) $m[1]);
        }

        return $ref;
    }

    /**
     * Normalises a ref and, when it was a product name rather than a spelling variant, keeps that
     * name: it becomes the label when empty, else is appended as " (ORIGINAL)" unless already there.
     *
     * @return array{string, ?string} [ref, label]
     */
    public static function resolve(string $ref, ?string $label): array
    {
        $normalised = self::normalise($ref);
        if ($normalised === $ref || !isset(self::productIndex()[self::squash($ref)])) {
            return [$normalised, $label];
        }

        $original = trim($ref);
        $current = trim((string) $label);
        if ('' === $current) {
            return [$normalised, $original];
        }
        if (false !== mb_stripos($current, $original)) {
            return [$normalised, $label];
        }

        return [$normalised, $current.' ('.$original.')'];
    }

    /**
     * @return list<string>
     */
    public static function canonicals(): array
    {
        return array_keys(self::STANDARDS);
    }

    public static function describe(): string
    {
        return \sprintf(
            'Fluids and chemicals use a standard as "ref", spelled exactly as one of: %s; engine/gear oil by SAE grade, e.g. "SAE 5W-30", "SAE 75W-80". Variants ("dot4", "5w30") are normalised to the standard. Put the product name in "label" and its maker in "brand". A real part keeps its manufacturer reference as "ref".',
            implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', self::canonicals())),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function index(): array
    {
        static $index = null;
        if (null === $index) {
            $index = [];
            foreach (self::STANDARDS as $canonical => $names) {
                foreach ([$canonical, ...($names['aliases'] ?? []), ...($names['products'] ?? [])] as $spelling) {
                    $index[self::squash($spelling)] = $canonical;
                }
            }
        }

        return $index;
    }

    /**
     * @return array<string, true>
     */
    private static function productIndex(): array
    {
        static $index = null;
        if (null === $index) {
            $index = [];
            foreach (self::STANDARDS as $names) {
                foreach ($names['products'] ?? [] as $product) {
                    $index[self::squash($product)] = true;
                }
            }
        }

        return $index;
    }

    private static function squash(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(',', '.', $value);
        $value = strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ù' => 'u', 'û' => 'u', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c']);

        return (string) preg_replace('/[^a-z0-9.]+/', '', $value);
    }
}
