<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class CompareBrandsInput
{
    /**
     * @var list<string>
     */
    #[ApiProperty(
        description: 'The brands to rank, as the user spelled them, e.g. ["JAPANPARTS","ASHIKA"].',
        schema: ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
    )]
    #[Assert\Count(max: 50)]
    public array $brands = [];
}
