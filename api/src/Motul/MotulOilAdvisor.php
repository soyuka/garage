<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * Resolves a free-text vehicle + year into a Motul engine-oil recommendation: SAE grade, drain
 * interval, capacity and ACEA/API quality.
 *
 * The grade only makes sense once the EXACT variant is pinned — different engines of the same model
 * take different grades. Motul's free-text search often lists one engine twice (a generic "1.8" and
 * a "1.8 MZR" row), so a year+engine query ties even when there is really one answer. On a SMALL tie
 * we probe each candidate and, if their engine grades AGREE, return that grade (consensus); only a
 * genuine divergence — or too many candidates to probe without spamming — comes back as ambiguous.
 * Brand-neutral: surfaces the SAE grade + ACEA/API quality + interval, not the Motul product to buy.
 */
final class MotulOilAdvisor
{
    /** Most candidates we probe for a grade consensus before giving up — keeps the scrape polite. */
    private const PROBE_CAP = 3;

    public function __construct(private readonly MotulClient $client)
    {
    }

    /**
     * @return array<string, mixed> a structured tool payload (status ∈ matched|ambiguous|not_found)
     */
    public function advise(string $search, ?int $year = null, ?string $engine = null): array
    {
        $all = $this->client->searchVehicle($search);
        if ([] === $all) {
            return ['status' => 'not_found', 'query' => $search, 'candidates' => []];
        }

        // Motul's search-vehicle fuzzy-matches the make/model and returns cross-brand noise (a "Mazda 6"
        // query yields "Maeda" mini-cranes). Drop anything whose brand/model do not match what we asked
        // for, BEFORE narrowing — otherwise the noise (often with absurd year ranges that survive a
        // year filter) floods the candidate list and buries the real variant.
        $relevant = $this->relevant($all, $search);

        $matches = $this->narrow($relevant, $year, $engine);

        if ([] === $matches || \count($matches) > self::PROBE_CAP) {
            return [
                'status' => [] === $matches ? 'not_found' : 'ambiguous',
                'query' => $search,
                'hint' => 'Several variants match — re-call with the exact year and/or engine to pin one.',
                'candidates' => array_map($this->candidate(...), [] === $matches ? $relevant : $matches),
            ];
        }

        $probed = array_map(fn (MotulVehicle $v): array => [
            'vehicle' => $v,
            'categories' => $this->client->recommendations($v->id),
        ], $matches);

        $gradeSets = array_unique(array_map(
            fn (array $p): string => implode(',', $this->engineGrades($p['categories'])),
            $probed,
        ));

        // Consensus: every probed variant agrees on a non-empty engine grade set.
        if (1 === \count($gradeSets) && '' !== reset($gradeSets)) {
            return $this->matched($search, $probed);
        }

        // All probed variants agree on… nothing — vehicle exists in catalog but Motul has no grade data.
        if (1 === \count($gradeSets) && '' === reset($gradeSets)) {
            return [
                'status' => 'not_found',
                'query' => $search,
                'hint' => 'Vehicle found in Motul catalog but no engine oil grade data for these variants. Check the source URLs or use the ACEA spec from known facts.',
                'candidates' => array_map(fn (array $p): array => $this->candidate($p['vehicle']) + [
                    'sourceUrl' => $this->client->recommendationsUrl($p['vehicle']->id),
                ], $probed),
            ];
        }

        // Grades diverge across variants — the caller must pick; show each variant's grade.
        return [
            'status' => 'ambiguous',
            'query' => $search,
            'hint' => 'Variants take different grades — pick the one matching your engine.',
            'candidates' => array_map(fn (array $p): array => $this->candidate($p['vehicle']) + [
                'engineOilGrades' => $this->engineGrades($p['categories']),
            ], $probed),
        ];
    }

    /**
     * Pin an exact variant by its Motul id — no search, no narrowing. The id comes from a candidate
     * surfaced by a prior "ambiguous" {@see self::advise()} result, so the caller (a CTA the user
     * clicked) converges on the one row instead of re-tying on a coarse engine string. An id with no
     * engine grade (decommissioned page / parse miss) comes back not_found, never an empty match.
     *
     * @return array<string, mixed>
     */
    public function adviseVariant(string $motulId): array
    {
        $categories = $this->client->recommendations($motulId);
        if ([] === $this->engineGrades($categories)) {
            return [
                'status' => 'not_found',
                'query' => $motulId,
                'candidates' => [],
                'sourceUrl' => $this->client->recommendationsUrl($motulId),
                'hint' => 'Vehicle found in Motul catalog but no engine oil grade data for this variant. Check sourceUrl or use the ACEA spec from known facts.',
            ];
        }

        // matched() only needs the id to build the sourceUrl and the candidate handle; the search-time
        // label/year are not available on this path, so a minimal vehicle carrying the id is enough.
        $vehicle = new MotulVehicle($motulId, '', '', '', null, null, null);

        return $this->matched($motulId, [['vehicle' => $vehicle, 'categories' => $categories]]);
    }

    /**
     * @param list<array{vehicle: MotulVehicle, categories: list<MotulCategory>}> $probed
     *
     * @return array<string, mixed>
     */
    private function matched(string $search, array $probed): array
    {
        $lead = $probed[0];
        $engine = $this->engineCategory($lead['categories']);

        $result = [
            'status' => 'matched',
            'query' => $search,
            'vehicle' => $this->candidate($lead['vehicle']),
            'variants' => \count($probed) > 1 ? array_map(fn (array $p): array => $this->candidate($p['vehicle']), $probed) : [],
            'engineOilGrades' => $this->engineGrades($lead['categories']),
            'sourceUrl' => $this->client->recommendationsUrl($lead['vehicle']->id),
        ];

        if (null !== $engine) {
            if ($engine->gradeVariesByCondition()) {
                $result['gradeByCondition'] = array_map(static fn (MotulConditionGrade $c): array => [
                    'usage' => $c->usage,
                    'mileage' => $c->mileage,
                    'viscosities' => $c->viscosities,
                ], $engine->conditions);
            }
            $result['oilChangeInterval'] = $engine->intervalForUsage('Normal') ?? $engine->intervalForUsage('Severe');
            $result['engineOilCapacities'] = array_map(self::parseCapacity(...), $engine->capacities);
            $result['aceaStandards'] = $engine->aceaStandards;
        }

        $result['otherFluids'] = array_values(array_filter(array_map(
            static function (MotulCategory $c): ?array {
                if ($c->isEngine() || [] === $c->viscosities()) {
                    return null;
                }

                return ['category' => $c->name, 'viscosities' => $c->viscosities(), 'capacities' => array_map(self::parseCapacity(...), $c->capacities)];
            },
            $lead['categories'],
        )));

        return $result;
    }

    /**
     * Keep only candidates whose brand AND model actually match the search, killing Motul's fuzzy
     * cross-brand noise. The first token of the search is the make, the rest the model. Matching is
     * alphanumeric-normalised, substring either-direction, and tolerant of the make leaking into a
     * model token ("MAZDA MAZDA6" — the @id path concatenates make+model — must still match Motul's
     * bare "6"). A search with no model token (make only) is left unfiltered.
     *
     * @param list<MotulVehicle> $vehicles
     *
     * @return list<MotulVehicle>
     */
    private function relevant(array $vehicles, string $search): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($search)) ?: []));
        if (\count($tokens) < 2) {
            return $vehicles;
        }

        $make = $this->norm($tokens[0]);
        $modelTokens = array_values(array_filter(array_map($this->norm(...), \array_slice($tokens, 1))));

        return array_values(array_filter($vehicles, function (MotulVehicle $v) use ($make, $modelTokens): bool {
            $brand = $this->norm($v->brand);
            if ('' === $brand || ('' !== $make && !str_contains($brand, $make) && !str_contains($make, $brand))) {
                return false;
            }

            $haystack = $this->norm($v->model.$v->type);
            foreach ($modelTokens as $token) {
                $stripped = '' !== $make ? str_replace($make, '', $token) : $token;
                foreach (array_unique([$token, $stripped]) as $needle) {
                    if ('' !== $needle && (str_contains($haystack, $needle) || str_contains($needle, $haystack))) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    private function norm(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }

    /**
     * @param list<MotulVehicle> $vehicles
     *
     * @return list<MotulVehicle>
     */
    private function narrow(array $vehicles, ?int $year, ?string $engine): array
    {
        if (null !== $year) {
            $vehicles = array_values(array_filter($vehicles, static fn (MotulVehicle $v): bool => $v->coversYear($year)));
        }

        if (null !== $engine && '' !== trim($engine)) {
            $byEngine = array_values(array_filter($vehicles, static fn (MotulVehicle $v): bool => false !== mb_stripos($v->type, trim($engine))));
            if ([] !== $byEngine) {
                $vehicles = $byEngine;
            }
        }

        return $vehicles;
    }

    /**
     * @param list<MotulCategory> $categories
     *
     * @return list<string>
     */
    private function engineGrades(array $categories): array
    {
        $engine = $this->engineCategory($categories);

        return null !== $engine ? $engine->viscosities() : [];
    }

    /**
     * @param list<MotulCategory> $categories
     */
    private function engineCategory(array $categories): ?MotulCategory
    {
        foreach ($categories as $category) {
            if ($category->isEngine()) {
                return $category;
            }
        }

        return null;
    }

    /**
     * "Capacity 4,6 liter" → "4.6 L"; leaves an unrecognised label untouched.
     */
    private static function parseCapacity(string $label): string
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:liter|litre|l)\b/i', $label, $m)) {
            return str_replace(',', '.', $m[1]).' L';
        }

        return $label;
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(MotulVehicle $v): array
    {
        $years = null !== $v->startYear ? \sprintf('%d-%s', $v->startYear, $v->endYear ?? '') : null;

        return array_filter([
            'id' => $v->id,
            'label' => trim(\sprintf('%s %s %s', $v->brand, $v->model, $v->type)),
            'years' => $years,
            'modelCode' => $v->modelCode,
        ], static fn (mixed $x): bool => null !== $x);
    }
}
