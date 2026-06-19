<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

final class MotulOilInput
{
    #[ApiProperty(description: 'Free-text vehicle to look up, e.g. "Mazda 6" or "Mazda 6 1.8". Make + model is enough; add the engine to help pin the variant.')]
    public string $search;

    #[ApiProperty(description: 'Model year to disambiguate the variant (e.g. 2004). Strongly recommended — narrows the many year-ranged listings to the matching one.')]
    public ?int $year = null;

    #[ApiProperty(description: 'Engine designation to disambiguate, e.g. "1.8", "2.0 CiTD". Matched against Motul\'s engine label.')]
    public ?string $engine = null;
}
