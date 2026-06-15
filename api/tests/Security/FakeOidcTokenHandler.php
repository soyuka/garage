<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Test-only stand-in for Symfony's cryptographic OidcTokenHandler. A real Zitadel-signed
 * JWT cannot be produced offline, so this decodes the opaque tokens minted by
 * {@see \App\Tests\Functional\FakeOidcToken} and hands the claims to the user provider as
 * UserBadge attributes — exactly as the real handler does after verifying a JWT.
 */
final class FakeOidcTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $decoded = base64_decode($accessToken, true);
        if (false === $decoded) {
            throw new BadCredentialsException('Invalid test token.');
        }

        $claims = json_decode($decoded, true);
        if (!\is_array($claims) || !isset($claims['sub']) || !\is_string($claims['sub'])) {
            throw new BadCredentialsException('Test token is missing the "sub" claim.');
        }

        return new UserBadge($claims['sub'], null, $claims);
    }
}
