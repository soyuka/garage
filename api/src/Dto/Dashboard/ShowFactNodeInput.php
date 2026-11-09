<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class ShowFactNodeInput
{
    #[ApiProperty(description: 'A stable identifier for the line, e.g. "oil_capacity". Used as the row key; when it matches a service type it also lights that body region on the car.')]
    #[Assert\Length(max: 100)]
    public string $key = '';

    #[ApiProperty(description: 'The label shown on the line, in the interface language, e.g. "Huile moteur" / "Engine oil".')]
    #[Assert\Length(max: 200)]
    public string $label = '';

    #[ApiProperty(description: 'The value, ALREADY formatted for display — the dashboard renders it verbatim. e.g. "4,3 l", "5W-30", "Diesel".')]
    #[Assert\Length(max: 2000)]
    public string $value = '';

    #[ApiProperty(description: 'True when the value comes from a catalog or official source (it wears a ✓); false when inferred.')]
    public bool $verified = false;

    #[ApiProperty(description: 'Optional secondary spec line under the value, e.g. "ACEA E3|E4|E5".')]
    #[Assert\Length(max: 2000)]
    public ?string $info = null;
}
