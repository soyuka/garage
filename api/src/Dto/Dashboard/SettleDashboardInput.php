<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class SettleDashboardInput
{
    #[ApiProperty(description: 'Optional closing toast, in French — sent before the settle so the orb takes its colour from it.')]
    public ?string $message = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['info', 'ok', 'warn', 'alarm'], 'description' => 'Tone of the closing toast. Defaults to "ok".'])]
    public string $tone = 'ok';
}
