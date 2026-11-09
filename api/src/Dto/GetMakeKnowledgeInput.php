<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class GetMakeKnowledgeInput
{
    #[ApiProperty(description: 'Vehicle make, any case or accent (e.g. "Peugeot", "CITROËN").')]
    #[Assert\Length(max: 100)]
    public string $make = '';

    #[ApiProperty(description: 'Topic slug from a previous listing (e.g. "numero-organisation"). Omit to list the topics available for the make.')]
    #[Assert\Length(max: 200)]
    public ?string $topic = null;
}
