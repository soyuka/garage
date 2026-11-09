<?php

declare(strict_types=1);

namespace App\Controller\Mcp;

use App\Security\McpResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 9728 protected-resource metadata. MCP clients fetch this (pointed to by the
 * WWW-Authenticate challenge on a 401) to discover which authorization server — Casdoor —
 * protects /mcp, then run the OAuth flow against it.
 *
 * Must stay publicly reachable: a client holding an expired token has to read this before
 * it can refresh, so it lives on its own unsecured firewall.
 */
final readonly class ProtectedResourceMetadataController
{
    public function __construct(
        #[Autowire(env: 'CASDOOR_ISSUER')]
        private string $issuer,
        private McpResource $resource,
    ) {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'mcp_oauth_protected_resource_metadata', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'resource' => $this->resource->for($request),
            'authorization_servers' => [rtrim($this->issuer, '/')],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
