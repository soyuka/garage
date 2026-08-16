<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ProposePartInput
{
    #[ApiProperty(description: 'The manufacturer part number, e.g. "W 712/73". Never invent one — it must come from the catalog, a web result or the user.')]
    public string $ref = '';

    #[ApiProperty(description: 'The brand, e.g. "MANN-FILTER".')]
    public ?string $brand = null;

    #[ApiProperty(description: 'A short label in the interface language, e.g. "Filtre à huile" / "Oil filter".')]
    public ?string $label = null;

    #[ApiProperty(description: 'Quantity, when more than one is needed.')]
    public ?int $qty = null;
}
