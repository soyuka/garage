<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;
use App\PartsApi\CarType;

interface PartsCatalog
{
    /**
     * @return list<Make>
     */
    public function makes(CarType $carType, ?string $search = null): array;

    /**
     * @return list<Model>
     */
    public function models(int $makeId, CarType $carType): array;

    /**
     * @return list<Car>
     */
    public function cars(int $makeId, int $modelId, CarType $carType): array;

    /**
     * @return list<PartCategory>
     */
    public function partCategories(int $carId, CarType $carType, bool $leafOnly = true, ?string $search = null): array;

    /**
     * The category's name from the part-category tree only when it is already cached: never an upstream call.
     */
    public function cachedCategoryName(int $carId, int $categoryId, CarType $carType): ?string;

    /**
     * @return list<Article>
     */
    public function articles(int $carId, int $categoryId, CarType $carType): array;

    /**
     * The category's articles only when the listing is already cached: never an upstream call.
     *
     * @return list<Article>|null
     */
    public function cachedArticles(int $carId, int $categoryId, CarType $carType): ?array;

    /**
     * Criteria are per ARTICLE, the union over every vehicle it fits, not per vehicle.
     *
     * @return list<ArticleCriterion>
     */
    public function articleCriteria(int $artId): array;

    /**
     * The criteria of those articles already cached, in one cache round trip: never an upstream call.
     *
     * @param list<int> $artIds
     *
     * @return array<int, list<ArticleCriterion>> by article id, uncached ones left out
     */
    public function cachedArticleCriteria(array $artIds): array;

    /**
     * @return list<ArticleCross>
     */
    public function articleCrosses(int $artId): array;

    public function upstreamCalls(): int;

    /**
     * @return array<string, int> paid upstream calls spent so far, by method
     */
    public function upstreamCallsByMethod(): array;
}
