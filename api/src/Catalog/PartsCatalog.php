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
 * TecDoc catalog reads over partsapi.ru. lang=4 = English group/article names.
 */
final class PartsCatalog
{
    private const LANG_EN = 4;

    public function __construct(private readonly PartsApiClient $client)
    {
    }

    /**
     * @return list<Make>
     */
    public function makes(CarType $carType): array
    {
        $rows = $this->client->fetch('getMakes', ['carType' => $carType->value]);

        return array_map(Make::fromPartsApi(...), $rows);
    }

    /**
     * @return list<Model>
     *
     * TODO params unverified — no getModels token yet (see docs/TODO.md).
     */
    public function models(int $makeId, CarType $carType): array
    {
        $rows = $this->client->fetch('getModels', ['manuId' => $makeId, 'carType' => $carType->value, 'lang' => self::LANG_EN]);

        return array_map(Model::fromPartsApi(...), $rows);
    }

    /**
     * @return list<Car>
     *
     * TODO params unverified — no getCars token yet (see docs/TODO.md).
     */
    public function cars(int $makeId, int $modelId, CarType $carType): array
    {
        $rows = $this->client->fetch('getCars', ['manuId' => $makeId, 'modelId' => $modelId, 'carType' => $carType->value, 'lang' => self::LANG_EN]);

        return array_map(Car::fromPartsApi(...), $rows);
    }

    /**
     * @return list<PartCategory>
     */
    public function partCategories(int $carId, CarType $carType, bool $leafOnly = true): array
    {
        $nodes = $this->client->fetch('getSearchTree', ['carId' => $carId, 'carType' => $carType->value, 'lang' => self::LANG_EN]);

        return PartCategory::fromPartsApiTree($nodes, $leafOnly);
    }

    /**
     * @return list<Article>
     */
    public function articles(int $carId, int $categoryId, CarType $carType): array
    {
        $rows = $this->client->fetch('getArticles', ['carId' => $carId, 'carType' => $carType->value, 'strId' => $categoryId, 'lang' => self::LANG_EN]);

        return array_map(Article::fromPartsApi(...), $rows);
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
