<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ReadDocumentationPageInput
{
    #[ApiProperty(description: 'REQUIRED. The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'REQUIRED. The documentId — from a search_documentation hit or the `id` of a list_documents entry.')]
    public ?string $document = null;

    #[ApiProperty(description: 'REQUIRED. The 1-based PHYSICAL page number — the `page` of a search_documentation hit, NOT its pageLabel.')]
    public ?int $page = null;
}
