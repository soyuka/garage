<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Catalog\CarType;
use Symfony\Component\Validator\Constraints as Assert;

final class ArticlesInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;

    /**
     * @var list<int>
     */
    #[ApiProperty(
        description: 'One or more part category ids (strId) from list_part_categories. Pass several at once (e.g. oil/air/fuel/cabin filters) to get them all in a single call.',
        schema: ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
    )]
    #[Assert\Count(max: 50)]
    public array $categoryIds = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => CarType::VALUES, 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car), CV (commercial vehicle) or MC (motorbike)'])]
    public string $carType = 'PC';

    /**
     * @var list<string>
     */
    #[ApiProperty(
        description: 'Restrict the answer to these brands, e.g. ["JAPANPARTS","ASHIKA"] — USE THIS whenever the user names a brand. Unfiltered, only the 6 best-ranked of a category\'s brands come back (a popular filter has 126), so a named brand is usually absent and answering from memory would be a guess. Filtered, the cap does not apply and any asked brand with no fitting article is listed under `missing` — say so plainly instead of comparing brands the catalogue does not carry for this part.',
        schema: ['type' => 'array', 'items' => ['type' => 'string']],
    )]
    #[Assert\Count(max: 50)]
    public array $brands = [];
}
