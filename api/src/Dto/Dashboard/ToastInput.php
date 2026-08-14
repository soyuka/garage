<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use ApiPlatform\Metadata\ApiProperty;

final class ToastInput
{
    #[ApiProperty(description: 'The one-line message, in French — what you just did or found.')]
    public string $message = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['info', 'ok', 'warn', 'alarm'], 'description' => 'Severity. "alarm" also docks a decision item in the à-décider inbox. Defaults to "info".'])]
    public string $tone = 'info';
}
