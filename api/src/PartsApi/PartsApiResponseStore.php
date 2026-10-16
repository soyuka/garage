<?php

declare(strict_types=1);

namespace App\PartsApi;

/**
 * Where PartsApiClient keeps every successful response, keyed by its `<method>.<sha1>` cache key.
 */
interface PartsApiResponseStore
{
    /**
     * @return list<array<string, mixed>>|null null on a miss or an expired entry
     */
    public function find(string $key): ?array;

    /**
     * @param list<string> $keys
     *
     * @return array<string, list<array<string, mixed>>> hits only, by key
     */
    public function findMany(array $keys): array;

    /**
     * @param array<string, int|string>  $params
     * @param list<array<string, mixed>> $data
     */
    public function save(string $key, string $method, array $params, array $data, ?int $ttlSeconds): void;
}
