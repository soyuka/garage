<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class CarsInput
{
    #[ApiProperty(description: 'TecDoc manufacturer id from list_makes')]
    public int $makeId;

    #[ApiProperty(description: 'TecDoc model id from list_models')]
    public int $modelId;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC', 'description' => 'TecDoc vehicle type: PC (passenger car) or CV (commercial vehicle)'])]
    public string $carType = 'PC';

    #[ApiProperty(description: 'Keep versions produced in this year (YEAR_START <= year <= YEAR_END; a version without an end is still produced).')]
    public ?int $year = null;

    #[ApiProperty(description: 'Engine displacement in litres, e.g. 2.0 (matches the litre part of the catalogue capacity, +-0.05).')]
    public ?float $capacityLitres = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['essence', 'diesel', 'hybride', 'électrique', 'GPL'], 'description' => 'Fuel of the engine.'])]
    public ?string $fuel = null;

    #[ApiProperty(description: 'Case-insensitive substring match on the version name or full model name, e.g. "GGES", "Sedan".')]
    #[Assert\Length(max: 200)]
    public ?string $search = null;
}
