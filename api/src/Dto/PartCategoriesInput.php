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
}
