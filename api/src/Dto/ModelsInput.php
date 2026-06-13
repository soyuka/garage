<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ModelsInput
{
    #[ApiProperty(description: 'TecDoc manufacturer id from list_makes')]
    public int $makeId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';
}
