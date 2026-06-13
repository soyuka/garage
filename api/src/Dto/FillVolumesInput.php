<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class FillVolumesInput
{
    #[ApiProperty(description: 'TecDoc kType from lookup_plate (carId) or list_cars (id)')]
    public int $carId;
}
