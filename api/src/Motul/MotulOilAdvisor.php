<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * Resolves a free-text vehicle + year into a Motul engine-oil grade.
 *
 * The grade only makes sense once the EXACT variant is pinned — different engines of the same model
 * take different grades. Motul's free-text search often lists one engine twice (a generic "1.8" and
 * a "1.8 MZR" row), so a year+engine query ties even when there is really one answer. On a SMALL tie
 * we therefore probe each candidate and, if their engine grades AGREE, return that grade (consensus);
 * only a genuine divergence — or too many candidates to probe without spamming — comes back as
 * ambiguous for the caller to narrow. Brand-neutral: surfaces the SAE grade + ACEA/API quality, not
 * the Motul product to buy.
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

        $matches = $this->narrow($all, $year, $engine);

        // Too many to pin, or none left after filtering: hand back the list, no grade.
        if ([] === $matches || \count($matches) > self::PROBE_CAP) {
            return [
                'status' => [] === $matches ? 'not_found' : 'ambiguous',
                'query' => $search,
                'hint' => 'Several variants match — re-call with the exact year and/or engine to pin one.',
                'candidates' => array_map($this->candidate(...), [] === $matches ? $all : $matches),
            ];
        }

        // 1..PROBE_CAP candidates: probe each and look for a grade consensus.
        $probed = array_map(fn (MotulVehicle $v): array => [
            'vehicle' => $v,
            'recommendations' => $this->client->recommendations($v->id),
        ], $matches);

        $gradeSets = array_unique(array_map(
            fn (array $p): string => implode(',', $this->engineGrades($p['recommendations'])),
            $probed,
        ));

        // Consensus: every probed variant agrees on a non-empty grade set.
        if (1 === \count($gradeSets) && '' !== reset($gradeSets)) {
            $lead = $probed[0];

            return [
                'status' => 'matched',
                'query' => $search,
                'vehicle' => $this->candidate($lead['vehicle']),
                'variants' => \count($probed) > 1 ? array_map(fn (array $p): array => $this->candidate($p['vehicle']), $probed) : [],
                'engineOilGrades' => $this->engineGrades($lead['recommendations']),
                'grades' => array_map(static fn (MotulOilRecommendation $r): array => [
                    'application' => $r->application,
                    'viscosity' => $r->viscosity,
                    'quality' => $r->quality,
                ], $lead['recommendations']),
                'sourceUrl' => $this->client->recommendationsUrl($lead['vehicle']->id),
            ];
        }

        // Grades diverge across variants — the caller must pick; show each variant's grade.
        return [
            'status' => 'ambiguous',
            'query' => $search,
            'hint' => 'Variants take different grades — pick the one matching your engine.',
            'candidates' => array_map(fn (array $p): array => $this->candidate($p['vehicle']) + [
                'engineOilGrades' => $this->engineGrades($p['recommendations']),
            ], $probed),
        ];
    }

    /**
     * @param list<MotulOilRecommendation> $recommendations
     *
     * @return list<string>
     */
    private function engineGrades(array $recommendations): array
    {
        return array_values(array_unique(array_map(
            static fn (MotulOilRecommendation $r): string => $r->viscosity,
            array_filter($recommendations, static fn (MotulOilRecommendation $r): bool => $r->isEngine() && '' !== $r->viscosity),
        )));
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
     * @return array<string, mixed>
     */
    private function candidate(MotulVehicle $v): array
    {
        $years = null !== $v->startYear ? sprintf('%d-%s', $v->startYear, $v->endYear ?? '') : null;

        return array_filter([
            'id' => $v->id,
            'label' => trim(sprintf('%s %s %s', $v->brand, $v->model, $v->type)),
            'years' => $years,
            'modelCode' => $v->modelCode,
        ], static fn (mixed $x): bool => null !== $x);
    }
}
