<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Clock\Clock;

/**
 * Deliberately not an HTTP exception, like {@see RecordNotFoundException}: api-platform/mcp would turn
 * a TooManyRequestsHttpException into a JSON-RPC -32603 before {@see \App\Mcp\DomainErrorHandler}
 * could answer the model with a structured `rate_limited` result. HTTP callers map it to 429 themselves.
 */
final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Rate limit exceeded.');
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfter->getTimestamp() - Clock::get()->now()->getTimestamp());
    }
}
