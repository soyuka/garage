<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\ApiResource\Article;
use App\Catalog\ArticleCriterion;
use App\Catalog\ArticleCross;
use App\Catalog\BrandTier;
use App\Catalog\CarType;
use App\Catalog\PartsCatalog;
use App\Catalog\VehicleLinkCriteria;
use App\Exception\UpstreamException;
use App\Mcp\ArticleGroup;

/**
 * Turns "220 fitment-correct articles" into the few physically distinct parts they really are.
 *
 * Every article of the category already fits the car, so what can differ is the part itself. One
 * representative per brand (best tiers first) is fingerprinted from its criteria, representatives are
 * clustered on physical dimensions, and ONE crosses call per cluster supplies its OE numbers and claims
 * the remaining articles. Clusters whose crosses share a carmaker OE number are then merged: the carmaker
 * itself treats them as interchangeable on this car, however different their dimensions. A represented brand
 * that sells another article the crosses leave unclaimed is selling a second part: that sibling is probed,
 * and kept apart from a physically matching cluster when the carmaker numbers it differently (no shared
 * carmaker OE), which is how build-breakpoint splits (PSA OPR) surface. The call budget is bounded:
 * MAX_REPRESENTATIVES + MAX_SIBLING_PROBES criteria, 1 crosses per cluster and per probe.
 *
 * partsapi quota is scarce (50/day/method on a test key), so any upstream failure degrades the answer
 * (`narrowed: false`) instead of failing it.
 */
final readonly class ArticleNarrower
{
    public const MAX_REPRESENTATIVES = 8;

    public const MAX_SIBLING_PROBES = 1;

    public function __construct(
        private PartsCatalog $catalog,
        private ArticleClusterer $clusterer = new ArticleClusterer(),
    ) {
    }

    public function narrow(int $carId, CarType $carType, int $categoryId, ?string $make = null, ?int $productGroupId = null): NarrowingResult
    {
        $make = $this->catalog->carMake($carId, $carType) ?? $make;
        $articles = $this->catalog->articles($carId, $categoryId, $carType);
        $productGroups = self::productGroups($articles);

        if (null === $productGroupId && \count($productGroups) > 1) {
            return new NarrowingResult(
                clusters: [],
                totalArticles: \count($articles),
                totalBrands: self::countBrands($articles),
                unclassifiedCount: \count($articles),
                differsOn: [],
                narrowed: false,
                degradedReason: null,
                productGroups: $productGroups,
                groupRequired: true,
            );
        }

        $productGroupId ??= $productGroups[0]['id'] ?? null;
        $productGroup = null;
        foreach ($productGroups as $group) {
            if ($group['id'] === $productGroupId) {
                $productGroup = ['id' => $group['id'], 'name' => $group['name']];
            }
        }
        $articles = null === $productGroup ? [] : array_values(array_filter($articles, static fn (Article $a): bool => $a->productTypeId === $productGroup['id']));
        $degraded = [];
        $links = $this->catalog->vehicleLinkCriteria($carId, $carType, array_map(static fn (Article $a): int => $a->id, $articles), $productGroup['id'] ?? null);
        $scope = null === $links ? ApplicabilityScope::Article : ApplicabilityScope::Vehicle;

        $representatives = $this->fingerprint($this->pickRepresentatives($articles), $links, $degraded);
        $clusters = $this->clusterer->cluster($representatives);

        if ([] === $clusters) {
            $degraded[] = 'no physical criteria available to tell the parts apart';

            return new NarrowingResult(
                clusters: [],
                totalArticles: \count($articles),
                totalBrands: self::countBrands($articles),
                unclassifiedCount: \count($articles),
                differsOn: [],
                narrowed: false,
                degradedReason: implode('; ', $degraded),
                fallbackBrands: ArticleGroup::of($categoryId, $articles)['brands'],
                productGroup: $productGroup,
                productGroups: $productGroups,
                applicabilityScope: $scope,
            );
        }

        $crosses = $this->fetchCrosses($clusters, $degraded);
        [$clusters, $crosses] = $this->mergeOnCarmakerOe($clusters, $crosses, $make);
        if ([] === $degraded) {
            [$clusters, $crosses] = $this->probeSiblings($clusters, $crosses, $articles, $make, $links, $degraded);
        }
        $oeBrands = [] === $crosses ? [] : $this->vehicleMakers($carType, $make, $degraded);

        $variants = $this->assign($clusters, $articles, $crosses, $links);
        $classified = array_sum(array_map('count', $variants));

        $narrowedClusters = [];
        foreach ($clusters as $cluster) {
            $best = $cluster->best();
            $narrowedClusters[] = new NarrowedCluster(
                id: $cluster->id,
                fingerprint: $cluster->merged()->toArray(),
                oeNumbers: self::oeNumbers($crosses[$cluster->id] ?? [], $oeBrands),
                representative: ['artId' => $best->artId, 'brand' => $best->brand, 'number' => $best->number],
                variants: $variants[$cluster->id],
                articleCount: \count($variants[$cluster->id]),
                applicability: null === $links
                    ? Applicability::union($this->applicabilities($cluster, $variants[$cluster->id]))
                    : Applicability::shared(array_column($variants[$cluster->id], 'applicability')),
                productGroup: $productGroup,
            );
        }

        return new NarrowingResult(
            clusters: $narrowedClusters,
            totalArticles: \count($articles),
            totalBrands: self::countBrands($articles),
            unclassifiedCount: \count($articles) - $classified,
            differsOn: $this->clusterer->differsOn($clusters),
            narrowed: [] === $degraded,
            degradedReason: [] === $degraded ? null : implode('; ', $degraded),
            productGroup: $productGroup,
            productGroups: $productGroups,
            applicabilityScope: $scope,
        );
    }

    /**
     * The members' breakpoints, then those of the other variants: a claimed variant may be the one article
     * that states the breakpoint.
     *
     * @param list<array{artId: int, brand: string, number: string, tier: int, applicability?: list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}>}> $variants
     *
     * @return list<array<string, list<string>>>
     */
    private function applicabilities(Cluster $cluster, array $variants): array
    {
        $sets = array_map(static fn (Representative $m): array => $m->applicability, $cluster->members);
        $members = array_flip(array_map(static fn (Representative $m): int => $m->artId, $cluster->members));
        $others = array_values(array_filter(array_column($variants, 'artId'), static fn (int $id): bool => !isset($members[$id])));
        foreach ($this->catalog->articleCriteriaByIds($others) as $criteria) {
            $sets[] = Applicability::fromCriteria($criteria);
        }

        return $sets;
    }

    /**
     * A category node can hold several TecDoc product groups (generic articles): "Direction > Rotules"
     * mixes outer tie rod ends with inner axial joints. They are different parts, never one choice.
     *
     * @param list<Article> $articles
     *
     * @return list<array{id: int, name: string, articleCount: int}> most articles first
     */
    private static function productGroups(array $articles): array
    {
        $groups = [];
        foreach ($articles as $article) {
            $groups[$article->productTypeId] ??= ['id' => $article->productTypeId, 'name' => $article->productGroup, 'articleCount' => 0];
            ++$groups[$article->productTypeId]['articleCount'];
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $b['articleCount'] <=> $a['articleCount'] ?: $a['id'] <=> $b['id']);

        return $groups;
    }

    /**
     * @param list<Article> $articles
     *
     * @return list<Article>
     */
    private function pickRepresentatives(array $articles): array
    {
        $byBrand = [];
        foreach ($articles as $article) {
            $byBrand[$article->brand][] = $article;
        }

        $brands = array_keys($byBrand);
        usort($brands, static fn (string $a, string $b): int => BrandTier::rank($a) <=> BrandTier::rank($b) ?: strcasecmp($a, $b));

        $picked = [];
        foreach (\array_slice($brands, 0, self::MAX_REPRESENTATIVES) as $brand) {
            $candidates = $byBrand[$brand];
            usort($candidates, static fn (Article $a, Article $b): int => strcmp($a->number, $b->number) ?: $a->id <=> $b->id);
            $picked[] = $candidates[0];
        }

        return $picked;
    }

    /**
     * @param list<Article>                        $picked
     * @param array<int, VehicleLinkCriteria>|null $links
     * @param list<string>                         $degraded
     *
     * @return list<Representative>
     */
    private function fingerprint(array $picked, ?array $links, array &$degraded): array
    {
        $representatives = [];
        foreach ($picked as $article) {
            try {
                $criteria = $this->criteria($article->id, $links);
            } catch (UpstreamException) {
                // The quota is per method: once it refuses, every further representative would too.
                $degraded[] = 'criteria unavailable from the parts catalog (quota or outage)';
                break;
            }

            $representatives[] = new Representative($article->id, $article->brand, $article->number, BrandTier::rank($article->brand), FingerprintBuilder::fromCriteria($criteria), Applicability::fromCriteria($criteria));
        }

        return $representatives;
    }

    /**
     * This car's link criteria when the catalog tells links apart, else the article's union over every vehicle.
     *
     * @param array<int, VehicleLinkCriteria>|null $links
     *
     * @return list<ArticleCriterion>
     */
    private function criteria(int $artId, ?array $links): array
    {
        if (null === $links) {
            return $this->catalog->articleCriteria($artId);
        }

        return ($links[$artId] ?? new VehicleLinkCriteria())->criteria();
    }

    /**
     * @param list<Cluster> $clusters
     * @param list<string>  $degraded
     *
     * @return array<string, list<ArticleCross>> by cluster id
     */
    private function fetchCrosses(array $clusters, array &$degraded): array
    {
        $crosses = [];
        foreach ($clusters as $cluster) {
            try {
                $crosses[$cluster->id] = $this->catalog->articleCrosses($cluster->best()->artId);
            } catch (UpstreamException) {
                $degraded[] = 'cross-references unavailable from the parts catalog (quota or outage): OE numbers and the remaining articles could not be classified';
                break;
            }
        }

        return $crosses;
    }

    /**
     * Two clusters whose crosses share a carmaker OE number are one part for this car: the carmaker
     * itself lists them as replacing each other. Other makers' OE numbers (a Ford sibling of the same
     * filter) say nothing about THIS car, so they never drive a merge. A cluster without crosses data
     * is left as it is.
     *
     * @param list<Cluster>                     $clusters
     * @param array<string, list<ArticleCross>> $crosses  by cluster id
     *
     * @return array{list<Cluster>, array<string, list<ArticleCross>>} renumbered clusters and their crosses
     */
    private function mergeOnCarmakerOe(array $clusters, array $crosses, ?string $make): array
    {
        if (null === $make || '' === $make || \count($clusters) < 2) {
            return [$clusters, $crosses];
        }

        $carmaker = self::normalize($make);
        $oe = [];
        foreach ($clusters as $cluster) {
            $oe[$cluster->id] = [];
            foreach ($crosses[$cluster->id] ?? [] as $cross) {
                if (self::normalize($cross->brand) === $carmaker && '' !== $cross->number) {
                    $oe[$cluster->id][self::normalizeOe($cross->number)] = true;
                }
            }
        }

        /** @var list<non-empty-list<Cluster>> $groups */
        $groups = [];
        /** @var list<array<string, true>> $groupOe */
        $groupOe = [];
        foreach ($clusters as $cluster) {
            $joined = [];
            foreach ($groupOe as $i => $numbers) {
                if ([] !== array_intersect_key($numbers, $oe[$cluster->id])) {
                    $joined[] = $i;
                }
            }

            if ([] === $joined) {
                $groups[] = [$cluster];
                $groupOe[] = $oe[$cluster->id];
                continue;
            }

            // A cluster can bridge two earlier groups: fold them all into the first.
            $first = array_shift($joined);
            $groups[$first][] = $cluster;
            $groupOe[$first] += $oe[$cluster->id];
            foreach ($joined as $i) {
                $groups[$first] = array_merge($groups[$first], $groups[$i]);
                $groupOe[$first] += $groupOe[$i];
                unset($groups[$i], $groupOe[$i]);
            }
        }

        $merged = [];
        $mergedCrosses = [];
        foreach (array_values($groups) as $i => $group) {
            $id = 'c'.($i + 1);
            $merged[] = Cluster::combine($id, $group);
            $mergedCrosses[$id] = array_merge(...array_map(static fn (Cluster $c): array => $crosses[$c->id] ?? [], $group));
        }
        usort($merged, static fn (Cluster $a, Cluster $b): int => Representative::compare($a->best(), $b->best()));

        $renumbered = [];
        $renumberedCrosses = [];
        foreach ($merged as $i => $cluster) {
            $id = 'c'.($i + 1);
            $renumbered[] = new Cluster($id, $cluster->members, $cluster->fingerprint);
            $renumberedCrosses[$id] = $mergedCrosses[$cluster->id];
        }

        return [$renumbered, array_filter($renumberedCrosses, static fn (array $list): bool => [] !== $list)];
    }

    /**
     * @param list<Cluster>                        $clusters
     * @param array<string, list<ArticleCross>>    $crosses  by cluster id
     * @param list<Article>                        $articles
     * @param array<int, VehicleLinkCriteria>|null $links
     * @param list<string>                         $degraded
     *
     * @return array{list<Cluster>, array<string, list<ArticleCross>>}
     */
    private function probeSiblings(array $clusters, array $crosses, array $articles, ?string $make, ?array $links, array &$degraded): array
    {
        $probed = [];
        for ($i = 0; $i < self::MAX_SIBLING_PROBES; ++$i) {
            $sibling = self::unclaimedSibling($clusters, $crosses, $articles, $probed);
            if (null === $sibling) {
                break;
            }
            $probed[$sibling->id] = true;

            try {
                $criteria = $this->criteria($sibling->id, $links);
            } catch (UpstreamException) {
                $degraded[] = 'criteria unavailable from the parts catalog (quota or outage): a second part of a represented brand could not be checked';
                break;
            }
            $probe = new Representative($sibling->id, $sibling->brand, $sibling->number, BrandTier::rank($sibling->brand), FingerprintBuilder::fromCriteria($criteria), Applicability::fromCriteria($criteria));
            if ($probe->fingerprint->isEmpty()) {
                continue;
            }

            try {
                $probeCrosses = $this->catalog->articleCrosses($sibling->id);
            } catch (UpstreamException) {
                $degraded[] = 'cross-references unavailable from the parts catalog (quota or outage): a second part of a represented brand could not be checked';
                break;
            }

            $probeOe = self::carmakerOe($probeCrosses, $make);
            $home = null;
            foreach ($clusters as $cluster) {
                if (!$cluster->fingerprint->agreesWith($probe->fingerprint)) {
                    continue;
                }
                $clusterOe = self::carmakerOe($crosses[$cluster->id] ?? [], $make);
                if ([] === $probeOe || [] === $clusterOe || [] !== array_intersect_key($probeOe, $clusterOe)) {
                    $home = $cluster;
                    break;
                }
            }

            if (null !== $home) {
                $home->members[] = $probe;
                usort($home->members, Representative::compare(...));
                $home->fingerprint = $home->fingerprint->completedBy($probe->fingerprint);
                $crosses[$home->id] = array_merge($crosses[$home->id] ?? [], $probeCrosses);
                continue;
            }

            $id = 'c'.(\count($clusters) + 1);
            $clusters[] = new Cluster($id, [$probe], $probe->fingerprint);
            $crosses[$id] = $probeCrosses;
            [$clusters, $crosses] = $this->mergeOnCarmakerOe($clusters, $crosses, $make);
        }

        return [$clusters, $crosses];
    }

    /**
     * The best-ranked article of a brand that already has a representative, which no member and no cross
     * of any cluster claims.
     *
     * @param list<Cluster>                     $clusters
     * @param array<string, list<ArticleCross>> $crosses
     * @param list<Article>                     $articles
     * @param array<int, true>                  $probed
     */
    private static function unclaimedSibling(array $clusters, array $crosses, array $articles, array $probed): ?Article
    {
        $brands = [];
        $claimed = $probed;
        foreach ($clusters as $cluster) {
            foreach ($cluster->members as $member) {
                $brands[$member->brand] = true;
                $claimed[$member->artId] = true;
            }
        }
        foreach ($crosses as $list) {
            foreach ($list as $cross) {
                if (null !== $cross->artId) {
                    $claimed[$cross->artId] = true;
                }
            }
        }

        $candidates = array_values(array_filter($articles, static fn (Article $a): bool => isset($brands[$a->brand]) && !isset($claimed[$a->id])));
        usort($candidates, static fn (Article $a, Article $b): int => BrandTier::rank($a->brand) <=> BrandTier::rank($b->brand)
            ?: strcmp($a->number, $b->number)
            ?: $a->id <=> $b->id);

        return $candidates[0] ?? null;
    }

    /**
     * @param list<ArticleCross> $crosses
     *
     * @return array<string, true> normalised OE numbers of the car's own make
     */
    private static function carmakerOe(array $crosses, ?string $make): array
    {
        if (null === $make || '' === $make) {
            return [];
        }

        $carmaker = self::normalize($make);
        $oe = [];
        foreach ($crosses as $cross) {
            if (self::normalize($cross->brand) === $carmaker && '' !== $cross->number) {
                $oe[self::normalizeOe($cross->number)] = true;
            }
        }

        return $oe;
    }

    /**
     * @param list<string> $degraded
     *
     * @return array<string, true> normalised vehicle-maker names
     */
    private function vehicleMakers(CarType $carType, ?string $make, array &$degraded): array
    {
        $makers = [];
        if (null !== $make && '' !== $make) {
            $makers[self::normalize($make)] = true;
        }

        try {
            foreach ($this->catalog->makes($carType) as $maker) {
                $makers[self::normalize($maker->name)] = true;
            }
        } catch (UpstreamException) {
            $degraded[] = 'vehicle makers unavailable from the parts catalog (quota or outage): OE numbers may be incomplete';
        }

        return $makers;
    }

    /**
     * A cross whose id is claimed by exactly one cluster joins it. An id claimed by several is left
     * unclassified: crosses are a loose interchange list, so merging through them would collapse
     * differently sized parts.
     *
     * @param list<Cluster>                        $clusters
     * @param list<Article>                        $articles
     * @param array<string, list<ArticleCross>>    $crosses
     * @param array<int, VehicleLinkCriteria>|null $links
     *
     * @return array<string, list<array{artId: int, brand: string, number: string, tier: int, applicability?: list<array{conditions: list<array{name: string, values: list<string>}>, pairedWith: list<string>}>}>> see {@see NarrowedCluster::$variants}
     */
    private function assign(array $clusters, array $articles, array $crosses, ?array $links): array
    {
        $claims = [];
        foreach ($crosses as $clusterId => $list) {
            foreach ($list as $cross) {
                if (null !== $cross->artId) {
                    $claims[$cross->artId][$clusterId] = true;
                }
            }
        }

        $members = [];
        $clusterOf = [];
        foreach ($clusters as $cluster) {
            $members[$cluster->id] = [];
            foreach ($cluster->members as $member) {
                $clusterOf[$member->artId] = $cluster->id;
            }
        }

        foreach ($articles as $article) {
            $clusterId = $clusterOf[$article->id] ?? null;
            if (null === $clusterId && 1 === \count($claims[$article->id] ?? [])) {
                $clusterId = array_key_first($claims[$article->id]);
            }
            if (null !== $clusterId) {
                $members[$clusterId][] = $article;
            }
        }

        return array_map(static function (array $list) use ($links): array {
            usort($list, static fn (Article $a, Article $b): int => BrandTier::rank($a->brand) <=> BrandTier::rank($b->brand)
                ?: strcasecmp($a->brand, $b->brand)
                ?: strcmp($a->number, $b->number)
                ?: $a->id <=> $b->id);

            return array_map(static fn (Article $a): array => [
                'artId' => $a->id,
                'brand' => $a->brand,
                'number' => $a->number,
                'tier' => BrandTier::classify($a->brand),
            ] + (null === $links ? [] : ['applicability' => Applicability::ofLink($links[$a->id] ?? new VehicleLinkCriteria())]), $list);
        }, $members);
    }

    /**
     * @param list<ArticleCross>  $crosses
     * @param array<string, true> $makers
     *
     * @return list<array{brand: string, number: string}>
     */
    private static function oeNumbers(array $crosses, array $makers): array
    {
        $oe = [];
        foreach ($crosses as $cross) {
            if (isset($makers[self::normalize($cross->brand)]) && '' !== $cross->number) {
                $oe[$cross->brand."\0".$cross->number] = ['brand' => $cross->brand, 'number' => $cross->number];
            }
        }

        $oe = array_values($oe);
        usort($oe, static fn (array $a, array $b): int => strcmp($a['brand'], $b['brand']) ?: strcmp($a['number'], $b['number']));

        return $oe;
    }

    /**
     * @param list<Article> $articles
     */
    private static function countBrands(array $articles): int
    {
        return \count(array_unique(array_map(static fn (Article $a): string => $a->brand, $articles)));
    }

    private static function normalizeOe(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s.\-\/]/', '', $number));
    }

    private static function normalize(string $brand): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $brand));
    }
}
