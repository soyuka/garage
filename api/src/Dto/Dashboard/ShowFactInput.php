<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ShowFactInput
{
    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Vehicle-fact keys to surface, e.g. ["oil_capacity", "oil_type"].'])]
    public array $keys = [];

    #[ApiProperty(description: 'Panel header, e.g. "Huile moteur".')]
    public ?string $title = null;
}
