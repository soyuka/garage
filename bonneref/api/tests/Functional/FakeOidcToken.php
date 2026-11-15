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
    // Must match CASDOOR_ORGANIZATION (.env.test).
    public const string ORGANIZATION = 'test-org';

    /**
     * @param list<string>|null $audience     defaults to both audiences, so one token works on /mcp and on REST
     * @param list<string>      $projectRoles
     */
    public static function for(string $sub, string $email, ?array $audience = null, array $projectRoles = []): string
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'owner' => self::ORGANIZATION,
            'aud' => $audience ?? [self::MCP_RESOURCE, self::ATELIER_CLIENT],
        ];

        if ([] !== $projectRoles) {
            $claims[self::ROLES_CLAIM] = array_fill_keys($projectRoles, ['orgid' => 'test-org']);
        }

        return base64_encode(json_encode($claims, \JSON_THROW_ON_ERROR));
    }

    /**
     * The shape Casdoor gives a client credentials token of mpa's `mpa-service` application (verified
     * 2026-11-15): the application's owner and name, no email.
     *
     * @param array<string, mixed> $override
     */
    public static function service(string $clientId, array $override = []): string
    {
        return base64_encode(json_encode($override + [
            'sub' => 'admin/mpa-service',
            'owner' => 'admin',
            'name' => 'mpa-service',
            'email' => '',
            'azp' => $clientId,
            'aud' => [$clientId],
            'tokenType' => 'access-token',
        ], \JSON_THROW_ON_ERROR));
    }
}
