<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class VinInput
{
    #[ApiProperty(description: '17-character VIN (chassis number, carte grise field E), any case, spaces or dashes tolerated.')]
    public string $vin;
}
