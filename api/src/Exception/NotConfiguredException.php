<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A capability the catalogue server (bonneref) has no provider key for. bonneref answers 501 for it
 * (its MissingApiKeyException); mpa cannot read bonneref's env, so that status is the whole signal.
 * Final, not retryable: retrying cannot configure a server.
 */
final class NotConfiguredException extends \RuntimeException
{
    public static function onCatalogueServer(string $feature): self
    {
        return new self(\sprintf('%s is not configured on the catalogue server', $feature));
    }
}
