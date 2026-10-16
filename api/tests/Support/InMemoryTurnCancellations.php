<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Agent\TurnCancellations;

final class InMemoryTurnCancellations implements TurnCancellations
{
    /** @var array<string, true> */
    private array $requested = [];

    public function request(string $sessionId): void
    {
        $this->requested[$sessionId] = true;
    }

    public function isRequested(string $sessionId): bool
    {
        return isset($this->requested[$sessionId]);
    }

    public function clear(string $sessionId): void
    {
        unset($this->requested[$sessionId]);
    }
}
