<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class VinInput
{
    #[ApiProperty(description: '17-character VIN (chassis number, carte grise field E), any case, spaces or dashes tolerated.')]
    #[Assert\Length(max: 24)]
    #[Assert\Regex('/^[A-Za-z0-9 -]+$/')]
    public string $vin;
}
