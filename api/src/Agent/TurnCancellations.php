<?php

declare(strict_types=1);

namespace App\Agent;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A cross-process "stop this turn" flag, keyed by session id. The cancel HTTP request lands in the
 * web container while the turn runs in the messenger worker (a separate process), so the flag must
 * live in a store both share — the app cache pool, backed by PostgreSQL here, not an in-memory or
 * per-container filesystem cache that the worker could never read.
 *
 * The flag is advisory and cooperative: {@see \App\Agent\Ui\CancelTurnListener} polls it at each
 * tool-call boundary and ends the turn cleanly. A short TTL is the backstop — a cancel that races a
 * turn which already finished simply expires instead of lingering to kill an unrelated later turn;
 * the handler also clears the flag at the start of every turn for the same reason.
 */
final readonly class TurnCancellations
{
    /** Long enough to outlive an in-flight LLM round-trip, short enough that a stale flag self-heals. */
    private const TTL_SECONDS = 120;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function request(string $sessionId): void
    {
        $item = $this->cache->getItem($this->key($sessionId));
        $item->set(true);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function isRequested(string $sessionId): bool
    {
        return $this->cache->getItem($this->key($sessionId))->isHit();
    }

    public function clear(string $sessionId): void
    {
        $this->cache->deleteItem($this->key($sessionId));
    }

    private function key(string $sessionId): string
    {
        // Cache keys forbid reserved chars ({}()/\@:); a session id is client-supplied, so hash it.
        return 'agent.cancel.'.hash('xxh128', $sessionId);
    }
}
