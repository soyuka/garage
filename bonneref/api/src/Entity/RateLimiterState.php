<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A rate limiter's state ({@see \App\RateLimiter\MongoRateLimiterStorage}), written and read through the raw
 * collection; mapped for the collection name and the TTL index only.
 */
#[ODM\Document(collection: 'rate_limiter_states')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'ttl_rate_limiter_states', expireAfterSeconds: 0)]
class RateLimiterState
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'date_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;
}
