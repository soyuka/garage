<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class OpenVehicleInput
{
    #[ApiProperty(description: 'What the user called the vehicle — a make, a model, a plate or a VIN, in whatever form they said it ("la Mazda", "MAZDA6", "AB-123-CD"). Matched as a case-insensitive substring; the most-recently-touched match wins.')]
    #[Assert\Length(max: 200)]
    public string $query = '';

    #[ApiProperty(
        description: 'Restrict the match to one vehicle segment. Omit unless the phrase is ambiguous across segments.',
        schema: ['type' => 'string', 'enum' => ['car', 'moto', 'cv', 'groundscare']],
    )]
    #[Assert\Length(max: 100)]
    public ?string $vehicleType = null;
}
