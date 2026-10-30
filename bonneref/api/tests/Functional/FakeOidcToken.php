<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Mints an opaque test token decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}.
 *
 * A real Zitadel-signed JWT cannot be produced offline, so the test firewall swaps the
 * cryptographic OidcTokenHandler for one that round-trips these claims. Everything
 * downstream (firewall, user provider, find-or-create, role mapping) runs for real. The
 * roles are emitted in Zitadel's project-roles claim shape so the provider parses the same
 * structure it sees in production.
 */
final class FakeOidcToken
{
    private const string ROLES_CLAIM = 'urn:zitadel:iam:org:project:roles';

    /**
     * @param list<string> $projectRoles Zitadel project role names (e.g. 'Admin', 'User')
     */
    public static function for(string $sub, string $email, array $projectRoles = []): string
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
        ];

        if ([] !== $projectRoles) {
            $claims[self::ROLES_CLAIM] = array_fill_keys($projectRoles, ['orgid' => 'test-org']);
        }

        return base64_encode(json_encode($claims, \JSON_THROW_ON_ERROR));
    }
}
