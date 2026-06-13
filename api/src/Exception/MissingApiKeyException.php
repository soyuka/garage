<?php

declare(strict_types=1);

namespace App\Exception;

final class MissingApiKeyException extends \RuntimeException
{
    public static function forPartsApiMethod(string $method): self
    {
        return new self(sprintf('No partsapi.ru API key configured for method "%s" (env PARTSAPI_KEY_%s).', $method, $method));
    }

    public static function forSiv(): self
    {
        return new self('No apiplaqueimmatriculation.com token configured (env API_PLAQUE_TOKEN).');
    }
}
