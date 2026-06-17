<?php

declare(strict_types=1);

namespace App\Mcp\Agent;

use Mcp\Server;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Exposes the garage MCP tools to the Symfony AI agent *in-process*, by driving the very same
 * DI {@see Server} the /mcp endpoint uses — no HTTP, no OAuth, no re-authored `#[AsTool]` schemas.
 * The tool definitions and the dedup/`ambiguous_match` semantics are reused verbatim; the agent is
 * a trusted in-process caller, so the Casdoor gate on /mcp (a real trust boundary for external
 * hosts) does not apply here.
 *
 * Each call completes a fresh `initialize` handshake then issues the request through a
 * {@see CapturingTransport}; the garage tools never use sampling/elicitation/progress, so a single
 * synchronous round-trip per call is enough (no SDK Client, no Fibers).
 */
final class McpToolbox implements ToolboxInterface
{
    private const PROTOCOL_VERSION = '2025-03-26';

    /**
     * The garage read/write tools the agent may drive. The bulky TecDoc catalog-browse tools
     * (list_makes/list_models/list_articles/…) are deliberately kept out of the agent context to
     * control token usage; lookup_plate is the one catalog entry point the garage flow needs.
     */
    private const ALLOWED = [
        'lookup_plate',
        'list_vehicles',
        'get_vehicle_facts',
        'add_vehicle_fact',
        'log_maintenance',
        'get_maintenance_history',
        'list_due_maintenance',
    ];

    /** @var list<Tool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Server $server)
    {
    }

    public function getTools(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $response = $this->drive(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()]);

        $tools = [];
        foreach ($response['result']['tools'] ?? [] as $definition) {
            if (!\in_array($definition['name'], self::ALLOWED, true)) {
                continue;
            }

            $tools[] = new Tool(
                new ExecutionReference(self::class, 'execute'),
                $definition['name'],
                $definition['description'] ?? '',
                $definition['inputSchema'] ?? null,
            );
        }

        return $this->tools = $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $response = $this->drive([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolCall->getName(),
                'arguments' => (object) $toolCall->getArguments(),
            ],
        ]);

        if (isset($response['error'])) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $result = $response['result'] ?? [];

        // Prefer the structured payload (what the dashboard / tests rely on); fall back to text.
        $payload = $result['structuredContent']
            ?? implode("\n", array_column($result['content'] ?? [], 'text'));

        return new ToolResult($toolCall, $payload);
    }

    /**
     * Run the DI MCP server in-process for one request, completing the initialize handshake first
     * so the request carries a valid session.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed> the decoded JSON-RPC response to $request
     */
    private function drive(array $request): array
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'in-process-agent', 'version' => '0.0.0'],
        ]];

        $frames = array_map(
            static fn (array $message): string => json_encode($message, \JSON_THROW_ON_ERROR),
            [$initialize, $request],
        );

        $responses = $this->server->run(new CapturingTransport($frames));

        // Frames come back in order: [initialize result, …, our request's response].
        return [] === $responses ? [] : $responses[array_key_last($responses)];
    }
}
