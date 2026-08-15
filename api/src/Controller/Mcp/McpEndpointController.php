<?php

declare(strict_types=1);

namespace App\Controller\Mcp;

use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces symfony/mcp-bundle's controller (service `mcp.server.controller`) for one reason: to make
 * the DNS-rebinding allowlist configurable.
 *
 * The bundle builds the transport with `middleware: null`, which selects
 * {@see StreamableHttpTransport::defaultMiddleware()} and therefore
 * {@see DnsRebindingProtectionMiddleware} with its localhost-only default. Served on a public
 * hostname, every AUTHENTICATED request then gets `403 Forbidden: Invalid Host header.` while
 * unauthenticated ones still get a correct 401 — so the endpoint looks like it is rejecting
 * credentials when it is rejecting the Host. That misreading cost a long debugging session on
 * 2026-08-15; see CONFERENCE.md workstream A.
 *
 * The middleware is kept, not dropped: the SDK docblock offers "omit it entirely when fronted by a
 * reverse proxy that enforces Host validation", but ours does not, and an allowlist we control is
 * the cheaper guarantee. The other two defaults are reproduced in the same order.
 */
final class McpEndpointController
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly Server $server,
        private readonly HttpMessageFactoryInterface $httpMessageFactory,
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $allowedHosts,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $transport = new StreamableHttpTransport(
            $this->httpMessageFactory->createRequest($request),
            $this->responseFactory,
            $this->streamFactory,
            logger: $this->logger,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware($this->allowedHosts, $this->responseFactory, $this->streamFactory),
                new ProtocolVersionMiddleware(),
            ],
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === strtolower($psrResponse->getHeaderLine('Content-Type'));

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}
