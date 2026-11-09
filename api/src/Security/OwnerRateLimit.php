<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Spends one slot of a per-owner budget, or refuses with 429 + Retry-After. Called after the request
 * is known to be well-formed (a malformed one costs nothing) and before anything is paid or stored.
 */
final readonly class OwnerRateLimit
{
    public function __construct(private OwnerContext $owner)
    {
    }

    public function consume(RateLimiterFactoryInterface $limiter, string $what): void
    {
        $limit = $limiter->create($this->owner->require())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), \sprintf('%s limit reached.', $what));
        }
    }
}
