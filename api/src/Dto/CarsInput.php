<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class CarsInput
{
    #[ApiProperty(description: 'TecDoc manufacturer id from list_makes')]
    public int $makeId;

    #[ApiProperty(description: 'TecDoc model id from list_models')]
    public int $modelId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'Item offset for paging through capped results. Use the nextOffset returned by a previous call.')]
    public int $offset = 0;
}
