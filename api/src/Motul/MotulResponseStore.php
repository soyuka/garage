<?php

declare(strict_types=1);

namespace App\Motul;

/**
 * Where MotulClient keeps every response, keyed `search.<sha1>` / `recs.<sha1>`.
 */
interface MotulResponseStore
{
    /**
     * @return array<mixed>|null
     */
    public function find(string $key): ?array;

    /**
     * @param array<mixed> $data
     */
    public function save(string $key, array $data): void;
}
