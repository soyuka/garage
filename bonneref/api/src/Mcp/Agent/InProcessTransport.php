<?php

declare(strict_types=1);

namespace App\Mcp\Agent;

use Mcp\Exception\LogicException;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\StatelessAwareTransportInterface;

/**
 * Carries one modern-era (MCP 2026-07-28) request into the DI {@see \Mcp\Server} in-process and keeps
 * its answer. Like stdio, it has no header layer, so the request metadata travels inline in `_meta`
 * ({@see StatelessProtocol::handleInline()}); there is no session and no handshake to replay.
 *
 * @extends BaseTransport<array<string, mixed>>
 */
final class InProcessTransport extends BaseTransport implements StatelessAwareTransportInterface
{
    private ?StatelessProtocol $stateless = null;

    /**
     * @param array<string, mixed> $request a JSON-RPC request carrying its `_meta` envelope
     */
    public function __construct(private readonly array $request)
    {
        parent::__construct();
    }

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
    }

    public function setHandshakeVersions(array $versions): void
    {
    }

    public function send(string $data, array $context): void
    {
        throw new LogicException('The in-process transport speaks the modern era only; nothing reaches the handshake dispatcher.');
    }

    /**
     * @return array<string, mixed> the decoded JSON-RPC response, or [] for a bodyless answer
     */
    public function listen(): array
    {
        if (null === $this->stateless) {
            throw new LogicException('The MCP server was built without the modern era (mcp.servers.*.protocol_versions); the in-process agent needs it.');
        }

        $result = $this->stateless->handleInline(json_encode($this->request, \JSON_THROW_ON_ERROR));

        if ($result->isEmpty() || $result->isStream()) {
            return [];
        }

        return json_decode($result->toJson(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
