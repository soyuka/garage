<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Mints an opaque test token decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}.
 *
 * A real Casdoor-signed JWT cannot be produced offline, so the test firewall swaps the
 * cryptographic handler for one that round-trips these claims. Everything downstream (the
 * audience check, firewall, user provider, find-or-create, role mapping) runs for real.
 */
final class FakeOidcToken
{
    private const string ROLES_CLAIM = 'urn:zitadel:iam:org:project:roles';

    // Must match MCP_RESOURCE_ALIASES (phpunit.dist.xml) and OAUTH_CLIENT_AUDIENCES (.env.test).
    public const string MCP_RESOURCE = 'https://mcp.example.test/mcp';
    public const string ATELIER_CLIENT = 'test-atelier-client';

    /**
     * @param list<string>|null $audience     defaults to both audiences, so one token works on /mcp and on REST
     * @param list<string>      $projectRoles
     */
    public static function for(string $sub, string $email, ?array $audience = null, array $projectRoles = []): string
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'aud' => $audience ?? [self::MCP_RESOURCE, self::ATELIER_CLIENT],
        ];

        if ([] !== $projectRoles) {
            $claims[self::ROLES_CLAIM] = array_fill_keys($projectRoles, ['orgid' => 'test-org']);
        }

        return base64_encode(json_encode($claims, \JSON_THROW_ON_ERROR));
    }
}
