<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

/**
 * @phpstan-import-type ClusterArray from NarrowedCluster
 *
 * @phpstan-type Divergence array{key: string, label: string, values: list<array{clusterId: string, label: string, value: string, min?: float, max?: float, unit?: ?string, info?: true}>}
 */
final readonly class NarrowingResult
{
    /**
     * @param list<NarrowedCluster>                                 $clusters
     * @param list<Divergence>                                      $differsOn
     * @param list<array<string, mixed>>|null                       $fallbackBrands     the plain BrandTier grouping, when clusters could not be built
     * @param array{id: int, name: string}|null                     $productGroup       the TecDoc product group narrowed, null when none applies
     * @param list<array{id: int, name: string, articleCount: int}> $productGroups      every product group of the category
     * @param bool                                                  $groupRequired      several product groups and none chosen: nothing was narrowed
     * @param ApplicabilityScope                                    $applicabilityScope where the applicability is: on each cluster (article union) or on each variant (this car's link)
     */
    public function __construct(
        public array $clusters,
        public int $totalArticles,
        public int $totalBrands,
        public int $unclassifiedCount,
        public array $differsOn,
        public bool $narrowed,
        public ?string $degradedReason,
        public ?array $fallbackBrands = null,
        public ?array $productGroup = null,
        public array $productGroups = [],
        public bool $groupRequired = false,
        public ApplicabilityScope $applicabilityScope = ApplicabilityScope::Article,
    ) {
    }

    /**
     * @return array{
     *     clusters: list<ClusterArray>,
     *     totalArticles: int,
     *     totalBrands: int,
     *     unclassifiedCount: int,
     *     differsOn: list<Divergence>,
     *     narrowed: bool,
     *     degradedReason: ?string,
     *     fallbackBrands: list<array<string, mixed>>|null,
     *     applicabilityScope: string,
     *     productGroup: array{id: int, name: string}|null,
     *     productGroups: list<array{id: int, name: string, articleCount: int}>,
     *     groupRequired: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'clusters' => array_map(static fn (NarrowedCluster $c): array => $c->toArray(), $this->clusters),
            'totalArticles' => $this->totalArticles,
            'totalBrands' => $this->totalBrands,
            'unclassifiedCount' => $this->unclassifiedCount,
            'differsOn' => $this->differsOn,
            'narrowed' => $this->narrowed,
            'degradedReason' => $this->degradedReason,
            'fallbackBrands' => $this->fallbackBrands,
            'applicabilityScope' => $this->applicabilityScope->value,
            'productGroup' => $this->productGroup,
            'productGroups' => $this->productGroups,
            'groupRequired' => $this->groupRequired,
        ];
    }
}
