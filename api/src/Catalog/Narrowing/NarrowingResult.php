<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class NarrowingResult
{
    /**
     * @param list<NarrowedCluster>                                                                  $clusters
     * @param list<array{key: string, label: string, values: list<array{clusterId: string, value: string}>}> $differsOn
     * @param list<array<string, mixed>>|null                                                        $fallbackBrands the plain BrandTier grouping, when clusters could not be built
     */
    public function __construct(
        public array $clusters,
        public int $totalArticles,
        public int $totalBrands,
        public int $unclassifiedCount,
        public array $differsOn,
        public bool $narrowed,
        public ?string $degradedReason,
        public int $upstreamCalls,
        public ?array $fallbackBrands = null,
    ) {
    }

    /**
     * @return array<string, mixed>
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
            'upstreamCalls' => $this->upstreamCalls,
            'fallbackBrands' => $this->fallbackBrands,
        ];
    }
}
