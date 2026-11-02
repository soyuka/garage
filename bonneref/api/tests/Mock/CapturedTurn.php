<?php

declare(strict_types=1);

namespace App\Tests\Mock;

final class CapturedTurn
{
    public int $calls = 0;

    public string $context = '';

    public bool $read = false;
}
