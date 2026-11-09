<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class MakesInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'Case-insensitive substring filter on the manufacturer name, e.g. "peugeot". Strongly recommended — TecDoc lists ~536 manufacturers.')]
    #[Assert\Length(max: 64)]
    public ?string $search = null;

    #[ApiProperty(description: 'Item offset for paging through capped results. Use the nextOffset returned by a previous call.')]
    #[Assert\PositiveOrZero]
    public int $offset = 0;
}
