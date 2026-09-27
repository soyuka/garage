<?php

declare(strict_types=1);

namespace App\Controller\Mcp;

use App\Mcp\Stream\DelegationPolicy;
use App\Mcp\Stream\McpStreamFactory;
use App\Mcp\Stream\MercureStreamResponseFactory;
use App\Mcp\Stream\PendingCall;
use App\Mcp\Stream\PendingCallStore;
use App\Security\OwnerContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a tool call with a stream the hub serves, instead of one PHP holds open.
 *
 * Only `tools/call` is delegated. The handshake and the list methods must answer on the response
 * itself — `initialize` carries the session id and the negotiated capabilities, and a client that
 * received those over a stream would have nothing to key its next request on.
 *
 * MCP_STREAM_DELEGATION turns this off. Every real client sends `Accept: application/json,
 * text/event-stream`, so with it on, every tool call goes through Caddy, the hub and the lifecycle
 * callback; the suite runs none of those and turns it off.
 *
 * Headers the replay needs are carried on the held call: the lifecycle callback that triggers it is
 * a separate request, with no session id, no protocol version and no bearer.
 */
final readonly class DelegatingMcpController
{
    private const array CARRIED_HEADERS = [
        'Content-Type',
        'Accept',
        'Mcp-Session-Id',
        'Mcp-Protocol-Version',
        'Host',
    ];

    public function __construct(
        private McpController $inner,
        private McpStreamFactory $streams,
        private PendingCallStore $pending,
        private MercureStreamResponseFactory $delegate,
        private OwnerContext $owner,
        private ResponseFactoryInterface $responses,
        private HttpFoundationFactoryInterface $httpFoundation,
        private DelegationPolicy $policy,
    ) {
    }

    public function handle(Request $request): Response
    {
        $body = $request->getContent();

        if (!$this->policy->shouldDelegate($request, $body)) {
            return $this->inner->handle($request);
        }

        $stream = $this->streams->open();

        $headers = [];
        foreach (self::CARRIED_HEADERS as $name) {
            if (null !== $value = $request->headers->get($name)) {
                $headers[$name] = $value;
            }
        }

        $this->pending->save($stream->topic, new PendingCall($body, $headers, $this->owner->current()));

        return $this->httpFoundation->createResponse($this->delegate->delegate(
            $this->responses->createResponse(200),
            $stream->topic,
            $stream->subscriberToken,
        ));
    }
}
