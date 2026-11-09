<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Siv\SivLookupStore;

final class InMemorySivLookupStore implements SivLookupStore
{
    /** @var array<string, array<array-key, mixed>> */
    public private(set) array $envelopes = [];

    public function find(string $plate): ?array
    {
        return $this->envelopes[$plate] ?? null;
    }

    public function save(string $plate, array $envelope): void
    {
        $this->envelopes[$plate] = $envelope;
    }
}
