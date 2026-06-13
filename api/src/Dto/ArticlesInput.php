<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ArticlesInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;

    #[ApiProperty(description: 'Part category id (strId) from list_part_categories')]
    public int $categoryId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';
}
