<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class PartCategoriesInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'Only return leaf categories (the ones that carry articles). Default true.')]
    public bool $leafOnly = true;

    #[ApiProperty(description: 'Case-insensitive substring filter on the category path, e.g. "filter", "oil", "brake". Strongly recommended — the full tree is large.')]
    public ?string $search = null;

    #[ApiProperty(description: 'Item offset for paging through capped results. Use the nextOffset returned by a previous call.')]
    public int $offset = 0;
}
