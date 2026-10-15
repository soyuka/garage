<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\FillVolume;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;

/**
 * TecDoc catalog reads over partsapi.ru. $lang is the partsapi language id for group/article
 * names (6 = Français, 4 = English GB — see docs/PARTSAPI.md); it is part of the cache key,
 * so switching languages re-fetches each method once.
 */
final class PartsCatalog
{
    public function __construct(
        private readonly PartsApiClient $client,
        private readonly int $lang = 6,
    ) {
    }

    /**
     * @return list<Make>
     */
    public function makes(CarType $carType, ?string $search = null): array
    {
        $rows = $this->client->fetch('getMakes', ['carType' => $carType->value]);
        $makes = array_map(Make::fromPartsApi(...), $rows);

        if (null === $search || '' === $search) {
            return $makes;
        }

        return array_values(array_filter($makes, static fn (Make $make): bool => false !== mb_stripos($make->name, $search)));
    }

    /**
     * Upstream repeats rows (same modelId and modelName), so they are collapsed on that pair.
     *
     * @return list<Model>
     */
    public function models(int $makeId, CarType $carType): array
    {
        $rows = $this->client->fetch('getModels', ['makeId' => $makeId, 'carType' => $carType->value, 'lang' => $this->lang]);

        $models = [];
        foreach ($rows as $row) {
            $model = Model::fromPartsApi($row);
            $models[$model->id.'|'.$model->name] ??= $model;
        }

        return array_values($models);
    }

    /**
     * @return list<Car>
     */
    public function cars(int $makeId, int $modelId, CarType $carType): array
    {
        $rows = $this->client->fetch('getCars', ['makeId' => $makeId, 'modelId' => $modelId, 'carType' => $carType->value, 'lang' => $this->lang]);

        return array_map(Car::fromPartsApi(...), $rows);
    }

    /**
     * @return list<PartCategory>
     */
    public function partCategories(int $carId, CarType $carType, bool $leafOnly = true, ?string $search = null): array
    {
        $nodes = $this->client->fetch('getSearchTree', ['carId' => $carId, 'carType' => $carType->value, 'lang' => $this->lang]);

        return PartCategory::fromPartsApiTree($nodes, $leafOnly, $search);
    }

    /**
     * The category's name from the part-category tree only when it is already cached: never an upstream call.
     */
    public function cachedCategoryName(int $carId, int $categoryId, CarType $carType): ?string
    {
        $nodes = $this->client->peek('getSearchTree', ['carId' => $carId, 'carType' => $carType->value, 'lang' => $this->lang]);
        if (null === $nodes) {
            return null;
        }
        foreach (PartCategory::fromPartsApiTree($nodes, false) as $category) {
            if ($category->id === $categoryId) {
                $label = $category->label();

                return '' === $label ? null : $label;
            }
        }

        return null;
    }

    /**
     * @return list<Article>
     */
    public function articles(int $carId, int $categoryId, CarType $carType): array
    {
        $rows = $this->client->fetch('getArticles', ['carId' => $carId, 'carType' => $carType->value, 'strId' => $categoryId, 'lang' => $this->lang]);

        return array_map(Article::fromPartsApi(...), $rows);
    }

    /**
     * Criteria are per ARTICLE, the union over every vehicle it fits — not per vehicle.
     *
     * @return list<ArticleCriterion>
     */
    public function articleCriteria(int $artId): array
    {
        $rows = $this->client->fetch('getArticleCriteria', ['ART_ID' => $artId, 'LANG' => $this->lang]);

        return array_map(ArticleCriterion::fromPartsApi(...), $rows);
    }

    /**
     * @return list<ArticleCross>
     */
    public function articleCrosses(int $artId): array
    {
        $rows = $this->client->fetch('getArticleCrosses', ['ART_ID' => $artId, 'LANG' => $this->lang]);

        return array_map(ArticleCross::fromPartsApi(...), $rows);
    }

    public function upstreamCalls(): int
    {
        return $this->client->upstreamCalls;
    }

    /**
     * @return list<FillVolume>
     */
    public function fillVolumes(int $carId): array
    {
        $rows = $this->client->fetch('GetFillVolumes', ['carId' => $carId]);

        return FillVolume::fromPartsApi($rows);
    }
}
