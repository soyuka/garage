<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Motul\MotulResponseStore;

final class InMemoryMotulResponseStore implements MotulResponseStore
{
    /** @var array<string, array<mixed>> */
    private array $responses = [];

    public function find(string $key): ?array
    {
        return $this->responses[$key] ?? null;
    }

    public function save(string $key, array $data): void
    {
        $this->responses[$key] = $data;
    }
}
