<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

use App\ApiResource\Article;
use App\Catalog\ArticleCross;
use App\Catalog\BrandTier;
use App\Catalog\PartsCatalog;
use App\Exception\UpstreamException;
use App\Mcp\ArticleGroup;
use App\PartsApi\CarType;

/**
 * Turns "220 fitment-correct articles" into the few physically distinct parts they really are.
 *
 * Every article of the category already fits the car, so what can differ is the part itself. One
 * representative per brand (best tiers first) is fingerprinted from its criteria, representatives are
 * clustered on physical dimensions, and ONE crosses call per cluster supplies its OE numbers and claims
 * the remaining articles. Clusters whose crosses share a carmaker OE number are then merged: the carmaker
 * itself treats them as interchangeable on this car, however different their dimensions. The call budget is
 * bounded: MAX_REPRESENTATIVES criteria + 1 crosses per cluster.
 *
 * partsapi quota is scarce (50/day/method on a test key), so any upstream failure degrades the answer
 * (`narrowed: false`) instead of failing it.
 */
final class ArticleNarrower
{
    public const MAX_REPRESENTATIVES = 8;

    public function __construct(
        private readonly PartsCatalog $catalog,
        private readonly ArticleClusterer $clusterer = new ArticleClusterer(),
    ) {
    }

    public function narrow(int $carId, CarType $carType, int $categoryId, ?string $make = null, ?int $productGroupId = null): NarrowingResult
    {
        $callsBefore = $this->catalog->upstreamCalls();
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
                upstreamCalls: $this->catalog->upstreamCalls() - $callsBefore,
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

        $representatives = $this->fingerprint($this->pickRepresentatives($articles), $degraded);
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
                upstreamCalls: $this->catalog->upstreamCalls() - $callsBefore,
                fallbackBrands: ArticleGroup::of($categoryId, $articles)['brands'],
                productGroup: $productGroup,
                productGroups: $productGroups,
            );
        }

        $crosses = $this->fetchCrosses($clusters, $degraded);
        [$clusters, $crosses] = $this->mergeOnCarmakerOe($clusters, $crosses, $make);
        $oeBrands = [] === $crosses ? [] : $this->vehicleMakers($carType, $make, $degraded);

        $variants = $this->assign($clusters, $articles, $crosses);
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
                applicability: Applicability::union(array_map(static fn (Representative $m): array => $m->applicability, $cluster->members)),
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
            upstreamCalls: $this->catalog->upstreamCalls() - $callsBefore,
            productGroup: $productGroup,
            productGroups: $productGroups,
        );
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
     * @param list<Article> $picked
     * @param list<string>  $degraded
     *
     * @return list<Representative>
     */
    private function fingerprint(array $picked, array &$degraded): array
    {
        $representatives = [];
        foreach ($picked as $article) {
            try {
                $criteria = $this->catalog->articleCriteria($article->id);
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

        /** @var list<list<Cluster>> $groups */
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
     * @param list<Cluster>                         $clusters
     * @param list<Article>                         $articles
     * @param array<string, list<ArticleCross>> $crosses
     *
     * @return array<string, list<array{artId: int, brand: string, number: string, tier: int}>>
     */
    private function assign(array $clusters, array $articles, array $crosses): array
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

        return array_map(static function (array $list): array {
            usort($list, static fn (Article $a, Article $b): int => BrandTier::rank($a->brand) <=> BrandTier::rank($b->brand)
                ?: strcasecmp($a->brand, $b->brand)
                ?: strcmp($a->number, $b->number)
                ?: $a->id <=> $b->id);

            return array_map(static fn (Article $a): array => [
                'artId' => $a->id,
                'brand' => $a->brand,
                'number' => $a->number,
                'tier' => BrandTier::classify($a->brand),
            ], $list);
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
