<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class CompareBrandsInput
{
    /**
     * @var list<string>
     */
    #[ApiProperty(
        description: 'The brands to rank, as the user spelled them, e.g. ["JAPANPARTS","ASHIKA"].',
        schema: ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
    )]
    public array $brands = [];
}
