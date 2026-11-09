<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ModelsInput
{
    #[ApiProperty(description: 'TecDoc manufacturer id from list_makes')]
    public int $makeId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'Case-insensitive substring match on the model name WITHOUT the make, e.g. "6", "3", "CX-5", "Clio" (not "MAZDA 6"; a leading make name is ignored). Recommended: a manufacturer can list hundreds of models and results are capped, so search instead of paging.')]
    #[Assert\Length(max: 200)]
    public ?string $search = null;

    #[ApiProperty(description: 'Item offset for paging through capped results. Use the nextOffset returned by a previous call.')]
    #[Assert\Range(min: 0, max: 10000)]
    public int $offset = 0;
}
