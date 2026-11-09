<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class SettleDashboardInput
{
    #[ApiProperty(description: 'Optional closing toast, in the interface language — sent before the settle so the orb takes its colour from it.')]
    #[Assert\Length(max: 2000)]
    public ?string $message = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['info', 'ok', 'warn', 'alarm'], 'description' => 'Tone of the closing toast. Defaults to "ok".'])]
    public string $tone = 'ok';
}
