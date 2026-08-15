<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ShowFactInput
{
    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Vehicle-fact keys to surface from the car\'s STORED sheet, e.g. ["oil_capacity", "oil_type"]. Leave empty when projecting `nodes`.'])]
    public array $keys = [];

    #[ApiProperty(description: 'Panel header, e.g. "Huile moteur".')]
    public ?string $title = null;

    /**
     * @var list<ShowFactNodeInput>
     */
    #[ApiProperty(description: 'Lines YOU fetched, projected as-is instead of filtering the stored facts — the way to show fill volumes, an oil grade or a carte grise, none of which are facts on the vehicle. Given `nodes`, `keys` no longer selects the content (it only spotlights car regions).')]
    public array $nodes = [];
}
