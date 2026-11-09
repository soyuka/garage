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
        schema: ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 40], 'maxItems' => 10, 'minItems' => 1],
    )]
    #[Assert\Count(min: 1, max: 10)]
    #[Assert\All([new Assert\Length(max: 40)])]
    public array $brands = [];
}
