<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The message is client-safe: in debug mode API Platform ships it as the 501 `detail`. The env var it
 * misses is the thrower's to log, never this message's to carry.
 */
final class MissingApiKeyException extends \RuntimeException
{
    private function __construct(string $message, public readonly string $source)
    {
        parent::__construct($message);
    }

    public static function forSiv(): self
    {
        return new self(
            'Plate lookup is not configured on this server.',
            'the French vehicle registry (SIV)',
        );
    }
}
