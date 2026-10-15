<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class NarrowPartsInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;

    #[ApiProperty(description: 'One part category id (strId) from list_part_categories, e.g. the oil filter category')]
    public int $categoryId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'The car\'s make as spelled by lookup_plate (e.g. "MAZDA"). Optional: it lets the car\'s own OE numbers be recognised even when the makes list spells the brand differently.')]
    public ?string $make = null;
}
