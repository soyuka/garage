<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class PlateInput
{
    #[ApiProperty(description: 'French registration plate, any format: "AB-123-CD", "ab123cd", "1234 AB 56"…')]
    public string $plate;
}
