<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class RemoveVehicleFactsInput
{
    #[ApiProperty(required: true, description: 'The vehicle @id (the IRI from list_vehicles, e.g. "/garage/vehicles/01J…").')]
    public string $vehicle;

    /**
     * @var list<string>
     */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string'], 'description' => 'Fact keys to remove, e.g. ["paint_code"].'])]
    public array $keys;
}
