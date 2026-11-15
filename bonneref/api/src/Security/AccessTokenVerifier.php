<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Signature, issuer, lifetime and token type of an access token, nothing about who it belongs to: the
 * caller decides whether the claims name a user ({@see CasdoorTokenHandler}) or mpa's service
 * ({@see ServiceTokenHandler}).
 */
interface AccessTokenVerifier
{
    /**
     * @return array<array-key, mixed> the verified claims
     *
     * @throws BadCredentialsException
     */
    public function verify(#[\SensitiveParameter] string $accessToken): array;
}
