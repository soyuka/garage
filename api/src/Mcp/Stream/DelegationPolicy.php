<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether an MCP request is answered over a delegated stream.
 *
 * Only `tools/call` qualifies. `initialize` carries the session id and the negotiated capabilities
 * on the response itself, and a client that received those over a stream would have nothing to key
 * its next request on; the list methods are a single small answer with nothing to stream.
 */
final readonly class DelegationPolicy
{
    public function __construct(
        #[Autowire('%env(bool:MCP_STREAM_DELEGATION)%')]
        private bool $enabled = true,
    ) {
    }

    public function shouldDelegate(Request $request, string $body): bool
    {
        if (!$this->enabled || !$request->isMethod('POST')) {
            return false;
        }

        if (!str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
            return false;
        }

        $message = json_decode($body, true);

        // A notification has no id: it is answered with 202 and no body, so nothing would be streamed.
        return \is_array($message)
            && 'tools/call' === ($message['method'] ?? null)
            && isset($message['id']);
    }
}
