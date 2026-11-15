<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Mints an opaque test token decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}.
 *
 * A real Casdoor-signed JWT cannot be produced offline, so the test firewall swaps the
 * cryptographic handler for one that round-trips these claims. Everything downstream (the
 * audience check, firewall, user provider, find-or-create) runs for real.
 */
final class FakeOidcToken
{
    // Must match MCP_RESOURCE_ALIASES (phpunit.dist.xml) and OAUTH_CLIENT_AUDIENCES (.env.test).
    public const string MCP_RESOURCE = 'https://mcp.example.test/mcp';
    public const string ATELIER_CLIENT = 'test-atelier-client';
    // CASDOOR_ORGANIZATION (.env.test).
    public const string ORGANIZATION = 'test-org';

    /**
     * @param list<string>|null $audience defaults to both audiences, so one token works on /mcp and on REST
     */
    public static function for(string $sub, string $email, ?array $audience = null): string
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'owner' => self::ORGANIZATION,
            'aud' => $audience ?? [self::MCP_RESOURCE, self::ATELIER_CLIENT],
        ];

        return base64_encode(json_encode($claims, \JSON_THROW_ON_ERROR));
    }
}
