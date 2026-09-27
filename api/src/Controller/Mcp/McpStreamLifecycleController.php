<?php

declare(strict_types=1);

namespace App\Controller\Mcp;

use App\Mcp\Stream\DeferredCalls;
use App\Mcp\Stream\PendingCallStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Caddy's side of the delegation handshake.
 *
 * `ready` means the hub has accepted the subscription, and it is the only safe moment to publish:
 * the stream module neither replays nor sends a Last-Event-ID, so anything published before this
 * callback is lost. `complete` and `cancelled` are best-effort notices — they release a call that
 * will never be streamed, for instance when the client hung up first.
 *
 * Unauthenticated by design: it is Caddy talking to the app over the compose network, carrying the
 * shared token rather than an owner's bearer. The owner rides on the held call instead.
 */
#[Route('/_mcp/stream-lifecycle', name: 'mcp_stream_lifecycle', methods: ['POST'])]
final readonly class McpStreamLifecycleController
{
    public function __construct(
        private DeferredCalls $deferred,
        private PendingCallStore $pending,
        #[Autowire('%env(MCP_STREAM_LIFECYCLE_TOKEN)%')]
        private string $token,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ('' === $this->token || !hash_equals('Bearer '.$this->token, (string) $request->headers->get('Authorization'))) {
            return new JsonResponse(['error' => 'Invalid lifecycle token.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $topic = \is_array($payload) ? ($payload['topic'] ?? null) : null;
        $state = \is_array($payload) ? ($payload['state'] ?? null) : null;

        if (!\is_string($topic) || '' === $topic || !\is_string($state)) {
            return new JsonResponse(['error' => 'A lifecycle notification carries a topic and a state.'], Response::HTTP_BAD_REQUEST);
        }

        match ($state) {
            'ready' => $this->deferred->defer($topic),
            // Nothing will read this stream now; drop the call rather than leave it to expire.
            'complete', 'cancelled' => $this->pending->take($topic),
            default => null,
        };

        // 204 exactly. The Caddy handler compares the status to http.StatusNoContent and aborts the
        // stream with a 502 on anything else, body unread (mercure-stream-delegation delegate.go:264).
        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
