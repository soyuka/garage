<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class ListVehiclesInput
{
    #[ApiProperty(description: 'Filter by make, e.g. "IVECO". Case-insensitive. Omit to list every make.')]
    public ?string $make = null;

    #[ApiProperty(description: 'Filter by model, e.g. "246-H". Case-insensitive. Pair with make to check whether a specific vehicle is already stored.')]
    public ?string $model = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Filter by vehicle segment. Omit to list every segment.'])]
    public ?string $vehicleType = null;

    #[ApiProperty(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'How many vehicles to return per page (most-recently-touched first). Defaults to 20.'])]
    public int $limit = 20;

    #[ApiProperty(schema: ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of vehicles to skip — for paging beyond the first page. Defaults to 0.'])]
    public int $offset = 0;

    #[ApiProperty(description: 'Also list archived vehicles (sold, scrapped or no longer in use). Defaults to false: archived vehicles are left out, and each row of an archived one carries archivedAt.')]
    public bool $includeArchived = false;

    public const int MAX_LIMIT = 50;
}
