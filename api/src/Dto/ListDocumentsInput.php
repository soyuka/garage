<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ListDocumentsInput
{
    #[ApiProperty(description: 'REQUIRED. The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    #[Assert\Length(max: 200)]
    public ?string $vehicle = null;
}
