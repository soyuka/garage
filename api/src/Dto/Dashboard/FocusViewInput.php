<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class FocusViewInput
{
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['tasks', 'specs'], 'description' => '"tasks" = the maintenance to-do list, "specs" = the spec/échéance panel.'])]
    public string $panel = 'tasks';

    /**
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service-type or fact keys to spotlight (e.g. ["oil_change"]); each lights its body region on the car. Omit to just raise the panel.'])]
    public array $keys = [];
}
