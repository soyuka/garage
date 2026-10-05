<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ShowDocumentInput
{
    #[ApiProperty(description: 'REQUIRED. The vehicle @id (the IRI from list_vehicles / open_vehicle, e.g. "/garage/vehicles/01J…").')]
    public ?string $vehicle = null;

    #[ApiProperty(description: 'REQUIRED. The documentId — from a search_documentation hit or the `id` of a list_documents entry.')]
    public ?string $document = null;

    #[ApiProperty(description: 'Optional. The 1-based PHYSICAL page to open at — the `page` of a search_documentation hit, NOT its pageLabel. Omit to open at the first page.')]
    public ?int $page = null;

    #[ApiProperty(description: 'Optional. Words to highlight in the document, typically your search_documentation query. Matched like the search: case- and accent-insensitive.')]
    public ?string $highlight = null;
}
