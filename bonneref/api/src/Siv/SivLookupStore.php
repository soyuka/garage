<?php

declare(strict_types=1);

namespace App\Siv;

/**
 * Where SivClient keeps every settled lookup (hit or unknown plate), keyed by the normalised plate.
 */
interface SivLookupStore
{
    /**
     * @return array<array-key, mixed>|null the full upstream envelope
     */
    public function find(string $plate): ?array;

    /**
     * @param array<array-key, mixed> $envelope
     */
    public function save(string $plate, array $envelope): void;
}
