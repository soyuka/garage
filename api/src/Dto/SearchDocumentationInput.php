<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class SearchDocumentationInput
{
    #[ApiProperty(description: 'REQUIRED. The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    #[Assert\Length(max: 200)]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'REQUIRED. Words to look for in the vehicle\'s documentation, e.g. "couple vidange boite". Case- and accent-insensitive; pages are ranked by how many of the words they contain.')]
    #[Assert\Length(max: 200)]
    public ?string $query = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['manual', 'technical_note', 'quote', 'other'], 'description' => 'Optional: only search documents of this kind.'])]
    public ?string $kind = null;

    #[ApiProperty(description: 'Maximum number of page hits returned. Defaults to 8.')]
    #[Assert\Range(min: 1, max: 50)]
    public int $limit = 8;
}
