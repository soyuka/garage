<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Catalog\CarType;
use Symfony\Component\Validator\Constraints as Assert;

final class NarrowPartsInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;

    #[ApiProperty(description: 'One part category id (strId) from list_part_categories, e.g. the oil filter category')]
    public int $categoryId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => CarType::VALUES, 'default' => 'PC', 'description' => 'TecDoc vehicle type matching the carId: PC (passenger car), CV (commercial vehicle) or MC (motorbike)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'The car\'s make as spelled by lookup_plate (e.g. "MAZDA"). Optional: it lets the car\'s own OE numbers be recognised even when the makes list spells the brand differently.')]
    #[Assert\Length(max: 100)]
    public ?string $make = null;

    #[ApiProperty(description: 'TecDoc product group id from productGroups, when a previous call answered groupRequired: true')]
    public ?int $productGroupId = null;
}
