<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\PartsApi\PartsApiResponseStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final class InMemoryPartsApiResponseStore implements PartsApiResponseStore
{
    /** @var array<string, array{method: string, params: array<string, int|string>, data: list<array<string, mixed>>, expiresAt: \DateTimeImmutable|null}> */
    public private(set) array $entries = [];

    public function __construct(private readonly ClockInterface $clock = new NativeClock())
    {
    }

    public function find(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;
        if (null === $entry || (null !== $entry['expiresAt'] && $entry['expiresAt'] <= $this->clock->now())) {
            return null;
        }

        return $entry['data'];
    }

    public function findMany(array $keys): array
    {
        $hits = [];
        foreach ($keys as $key) {
            if (null !== $data = $this->find($key)) {
                $hits[$key] = $data;
            }
        }

        return $hits;
    }

    public function save(string $key, string $method, array $params, array $data, ?int $ttlSeconds): void
    {
        $expiresAt = null === $ttlSeconds ? null : $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds));
        $this->entries[$key] = ['method' => $method, 'params' => $params, 'data' => $data, 'expiresAt' => $expiresAt];
    }
}
