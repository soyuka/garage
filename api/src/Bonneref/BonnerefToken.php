<?php

declare(strict_types=1);

namespace App\Bonneref;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The bearer to forward to bonneref when the request that carried it is gone: an agent turn runs in the
 * Messenger worker (or after the response, in terminate mode), so the turn message carries the token and
 * the handler holds it here for the length of the turn.
 */
final class BonnerefToken implements ResetInterface
{
    private ?string $token = null;

    public function set(?string $token): void
    {
        $this->token = '' === $token ? null : $token;
    }

    public function get(): ?string
    {
        return $this->token;
    }

    public function reset(): void
    {
        $this->token = null;
    }

    public static function fromRequest(?Request $request): ?string
    {
        $header = $request?->headers->get('Authorization');
        if (null === $header || 1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        return $m[1];
    }
}
