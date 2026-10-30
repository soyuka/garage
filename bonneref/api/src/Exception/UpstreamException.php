<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An upstream data source failed.
 *
 * `transient` is the load-bearing distinction: a stall, a 5xx or a dropped connection says nothing
 * about the request itself and is worth retrying, while a rejection is final. Collapsing the two is
 * what let a 15 s SIV stall reach the user as "cette plaque n'existe pas" on 2026-09-14.
 */
final class UpstreamException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly bool $transient = false,
        public readonly string $source = 'the upstream service',
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transient(string $source, string $message, ?\Throwable $previous = null): self
    {
        return new self($message, $previous, true, $source);
    }

    public static function definitive(string $source, string $message, ?\Throwable $previous = null): self
    {
        return new self($message, $previous, false, $source);
    }
}
