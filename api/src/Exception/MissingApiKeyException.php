<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * `source` is the model-safe name of the service. The message names the env var and stays for the
 * logs; it must never reach an MCP client (see App\Mcp\DomainErrorHandler).
 */
final class MissingApiKeyException extends \RuntimeException
{
    private function __construct(string $message, public readonly string $source)
    {
        parent::__construct($message);
    }

    public static function forPartsApiMethod(string $method): self
    {
        return new self(
            sprintf('No partsapi.ru API key configured for method "%s" (env PARTSAPI_KEY_%s).', $method, $method),
            'the parts catalog',
        );
    }

    public static function forSiv(): self
    {
        return new self(
            'No apiplaqueimmatriculation.com token configured (env API_PLAQUE_TOKEN).',
            'the French vehicle registry (SIV)',
        );
    }
}
