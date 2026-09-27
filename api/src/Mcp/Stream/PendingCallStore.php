<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Holds a {@see PendingCall} between the two requests that make up a delegated answer.
 *
 * Single-use on purpose: {@see take()} deletes as it reads, so a replayed or duplicated lifecycle
 * callback cannot run the same tool call twice.
 */
final readonly class PendingCallStore
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $ttl = 60,
    ) {
    }

    public function save(string $topic, PendingCall $call): void
    {
        $item = $this->cache->getItem(self::key($topic));
        $item->set($call);
        $item->expiresAfter($this->ttl);
        $this->cache->save($item);
    }

    public function take(string $topic): ?PendingCall
    {
        $key = self::key($topic);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $this->cache->deleteItem($key);
        $call = $item->get();

        return $call instanceof PendingCall ? $call : null;
    }

    private static function key(string $topic): string
    {
        // The topic is a URL; PSR-6 keys forbid most of its characters.
        return 'mcp_stream.'.hash('xxh128', $topic);
    }
}
