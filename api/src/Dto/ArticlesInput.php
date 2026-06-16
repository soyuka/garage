<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

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
    public array $categoryIds = [];

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';
}
