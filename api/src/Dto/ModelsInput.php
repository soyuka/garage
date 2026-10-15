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

    #[ApiProperty(description: 'Case-insensitive substring filter on the model name, e.g. "hatchback". Recommended — a manufacturer can list hundreds of models.')]
    public ?string $search = null;

    #[ApiProperty(description: 'Item offset for paging through capped results. Use the nextOffset returned by a previous call.')]
    public int $offset = 0;
}
