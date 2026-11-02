<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;

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
     * The carmaker's name for this kType, as the catalogue names it; null when the catalogue does not know the car.
     */
    public function carMake(int $carId, CarType $carType): ?string;

    /**
     * The make, model and type names of this kType as the catalogue names them; null when the catalogue does not know the car.
     *
     * @return array{make: string, model: string, engine: string}|null
     */
    public function carIdentity(int $carId, CarType $carType): ?array;

    /**
     * @return list<PartCategory>
     */
    public function partCategories(int $carId, CarType $carType, bool $leafOnly = true, ?string $search = null): array;

    /**
     * The category's name in the car's part-category tree; null when the tree has no such category.
     */
    public function categoryName(int $carId, int $categoryId, CarType $carType): ?string;

    /**
     * @return list<Article>
     */
    public function articles(int $carId, int $categoryId, CarType $carType): array;

    /**
     * The article's attributes, then its link qualifiers merged over EVERY vehicle it fits: the shape partsapi
     * answers with, for when no vehicle is in scope. With a vehicle, prefer {@see self::vehicleLinkCriteria()}.
     *
     * @return list<ArticleCriterion>
     */
    public function articleCriteria(int $artId): array;

    /**
     * {@see self::articleCriteria()} for several articles in one query.
     *
     * @param list<int> $artIds
     *
     * @return array<int, list<ArticleCriterion>> by article id, articles without criteria left out
     */
    public function articleCriteriaByIds(array $artIds): array;

    /**
     * The criteria of those articles on one vehicle's link (qualifiers of that link only, restricted to the product
     * group when given), never an upstream call. Null when the catalog cannot tell an article's links apart: partsapi
     * only knows the union of {@see self::articleCriteria()}.
     *
     * @param list<int> $artIds
     *
     * @return array<int, VehicleLinkCriteria>|null by article id, every asked id included
     */
    public function vehicleLinkCriteria(int $carId, CarType $carType, array $artIds, ?int $productGroupId = null): ?array;

    /**
     * @return list<ArticleCross>
     */
    public function articleCrosses(int $artId): array;
}
