<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class GetMakeKnowledgeInput
{
    #[ApiProperty(description: 'Vehicle make, any case or accent (e.g. "Peugeot", "CITROËN").')]
    public string $make = '';

    #[ApiProperty(description: 'Topic slug from a previous listing (e.g. "numero-organisation"). Omit to list the topics available for the make.')]
    public ?string $topic = null;
}
