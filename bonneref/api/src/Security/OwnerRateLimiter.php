<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class OwnerRateLimiter
{
    public function __construct(
        private RateLimiterFactoryInterface $limiter,
        private OwnerContext $owner,
        private ?RateLimiterFactoryInterface $global = null,
    ) {
    }

    /**
     * An ownerless caller shares one bucket: an unexpected path without a principal stays limited. The
     * global bucket, consumed only once the owner's accepted, caps the spend of many fresh accounts.
     *
     * @throws RateLimitExceededException
     */
    public function consume(): void
    {
        $limit = $this->limiter->create($this->owner->current() ?? '')->consume();

        if (!$limit->isAccepted()) {
            throw new RateLimitExceededException($limit->getRetryAfter());
        }

        $limit = $this->global?->create('global')->consume();
        if (null !== $limit && !$limit->isAccepted()) {
            throw new RateLimitExceededException($limit->getRetryAfter());
        }
    }
}
