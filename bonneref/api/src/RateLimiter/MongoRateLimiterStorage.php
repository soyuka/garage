<?php

declare(strict_types=1);

namespace App\RateLimiter;

use App\Entity\RateLimiterState;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Limiter state shared by every container (php, the messenger worker, replicas). cache.app is a filesystem
 * pool under each container's own /app/var, which gave each one its own buckets. The read-modify-write is
 * serialised by the limiter's lock, itself in MongoDB (config/packages/lock.yaml).
 */
final readonly class MongoRateLimiterStorage implements StorageInterface
{
    public function __construct(
        private DocumentManager $documents,
    ) {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $expiresIn = $limiterState->getExpirationTime();

        $this->documents->getDocumentCollection(RateLimiterState::class)->replaceOne(
            ['_id' => self::id($limiterState->getId())],
            [
                'state' => new Binary(serialize($limiterState)),
                'expiresAt' => null === $expiresIn ? null : new UTCDateTime((time() + $expiresIn) * 1000),
            ],
            ['upsert' => true],
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $document = $this->documents->getDocumentCollection(RateLimiterState::class)->findOne(
            ['_id' => self::id($limiterStateId)],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );
        if (!\is_array($document) || !$document['state'] instanceof Binary) {
            return null;
        }

        // The TTL monitor only sweeps once a minute.
        $expiresAt = $document['expiresAt'] ?? null;
        if ($expiresAt instanceof UTCDateTime && $expiresAt->toDateTime()->getTimestamp() <= time()) {
            return null;
        }

        $state = unserialize($document['state']->getData());

        return $state instanceof LimiterStateInterface ? $state : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->documents->getDocumentCollection(RateLimiterState::class)->deleteOne(['_id' => self::id($limiterStateId)]);
    }

    private static function id(string $limiterStateId): string
    {
        // The id embeds the owner's email: hashed, like cache.app keyed it.
        return hash('sha256', $limiterStateId);
    }
}
