<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Spends one slot of a per-owner budget, then of the optional global one shared by every owner, or
 * refuses with 429 + Retry-After. Called after the request is known to be well-formed (a malformed one
 * costs nothing) and before anything is paid or stored. Over MCP the refusal becomes a `rate_limited`
 * tool error ({@see \App\Mcp\DomainErrorHandler}).
 */
final readonly class OwnerRateLimit
{
    public function __construct(private OwnerContext $owner)
    {
    }

    public function consume(RateLimiterFactoryInterface $limiter, string $what, ?RateLimiterFactoryInterface $global = null): void
    {
        self::refuseUnlessAccepted($limiter->create($this->owner->require())->consume(), $what);
        if (null !== $global) {
            self::refuseUnlessAccepted($global->create('global')->consume(), $what);
        }
    }

    private static function refuseUnlessAccepted(RateLimit $limit, string $what): void
    {
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), \sprintf('%s limit reached.', $what));
        }
    }
}
