<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the RFC 9728 §5.1 WWW-Authenticate challenge to unauthorized /mcp responses,
 * pointing MCP clients at the protected-resource metadata document so they can bootstrap
 * the OAuth flow against Zitadel.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class McpProtectedResourceChallengeListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (Response::HTTP_UNAUTHORIZED !== $response->getStatusCode()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ('/mcp' !== $path && !str_starts_with($path, '/mcp/')) {
            return;
        }

        $existing = $response->headers->get('WWW-Authenticate');
        // The same 401 can pass through kernel.response twice (error sub-request then main request).
        if (null !== $existing && str_contains($existing, 'resource_metadata=')) {
            return;
        }

        $metadataUrl = $event->getRequest()->getSchemeAndHttpHost().'/.well-known/oauth-protected-resource';
        // Append to any existing Bearer challenge (e.g. error="invalid_token") rather than replacing it,
        // so the upstream auth error detail survives (RFC 9728 §5.1).
        $response->headers->set('WWW-Authenticate', null !== $existing
            ? $existing.',resource_metadata="'.$metadataUrl.'"'
            : \sprintf('Bearer resource_metadata="%s"', $metadataUrl));
    }
}
