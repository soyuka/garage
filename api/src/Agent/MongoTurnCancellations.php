<?php

declare(strict_types=1);

namespace App\Agent;

use App\Entity\TurnCancellation;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;

/**
 * Backed by MongoDB, which the php and worker containers share. The previous `cache.app` pool was a
 * filesystem pool under /app/var, an anonymous volume per container, so a cancel raised by php never
 * reached the worker.
 */
final readonly class MongoTurnCancellations implements TurnCancellations
{
    /** Long enough to outlive an in-flight LLM round-trip, short enough that a stale flag self-heals. */
    private const int TTL_SECONDS = 120;

    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public function request(string $sessionId): void
    {
        $this->dm->createQueryBuilder(TurnCancellation::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($this->id($sessionId))
            ->field('expiresAt')->set($this->clock->now()->modify(\sprintf('+%d seconds', self::TTL_SECONDS)))
            ->getQuery()
            ->execute();
    }

    public function isRequested(string $sessionId): bool
    {
        $count = $this->dm->createQueryBuilder(TurnCancellation::class)
            ->count()
            ->field('id')->equals($this->id($sessionId))
            ->field('expiresAt')->gt($this->clock->now())
            ->getQuery()
            ->execute();

        return \is_int($count) && $count > 0;
    }

    public function clear(string $sessionId): void
    {
        $this->dm->createQueryBuilder(TurnCancellation::class)->remove()->field('id')->equals($this->id($sessionId))->getQuery()->execute();
    }

    private function id(string $sessionId): string
    {
        // A session id is client-supplied; hashing bounds the _id length.
        return hash('xxh128', $sessionId);
    }
}
