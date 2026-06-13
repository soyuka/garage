<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class AddVehicleFactInput
{
    #[ApiProperty(description: 'Vehicle make, e.g. "TORO". Case-insensitive; the shared vehicle row is created if absent.')]
    public string $make;

    #[ApiProperty(description: 'Vehicle model, e.g. "246-H". Case-insensitive.')]
    public string $model;

    #[ApiProperty(description: 'Model year, when known.')]
    public ?int $year = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare'], 'description' => 'Vehicle segment. Defaults to "car".'])]
    public string $vehicleType = 'car';

    #[ApiProperty(description: 'Fact key, e.g. "oil_type", "oil_capacity", "spark_plug_ref".')]
    public string $key;

    #[ApiProperty(description: 'Fact value, e.g. "SAE 5W-30", "3.5".')]
    public string $value;

    #[ApiProperty(description: 'Unit when the value is a quantity, e.g. "liter".')]
    public ?string $unit = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['llm', 'user', 'web'], 'description' => 'Where the value came from. Defaults to "llm" (general knowledge, unverified). Use "web" with a url for a sourced fact.'])]
    public string $source = 'llm';

    #[ApiProperty(description: 'Source URL when source is web-sourced. Stored on the fact as provenance.')]
    public ?string $url = null;

    #[ApiProperty(description: 'Who/what added the fact (optional attribution).')]
    public ?string $addedBy = null;
}
