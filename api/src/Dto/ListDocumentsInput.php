<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ListDocumentsInput
{
    #[ApiProperty(description: 'REQUIRED. The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    public ?string $vehicle = null;
}
