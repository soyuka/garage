<?php

declare(strict_types=1);

namespace App\Mcp\Agent;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
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
class McpToolbox implements ToolboxInterface
{
    private const PROTOCOL_VERSION = '2025-03-26';

    /**
     * Tools withheld from the agent. The filter is a DENYLIST on purpose: every garage MCP tool is
     * meant for the agent, so it fails OPEN — a newly added tool surfaces to the agent automatically
     * and only an explicit entry here hides it. (An allowlist failed closed and silently stripped
     * update_vehicle/plan_maintenance when 586b223 added them without updating this list — the prompt
     * then commanded tools the agent could not see, so the usage write and the checklist no-op'd.)
     *
     * Excluded: the make/model browse roots (lookup_plate / list_cars resolve the carId directly),
     * and the granular catalog-walk tools — the service flow uses the single get_service_parts call
     * (parts + fluids in one round-trip) instead of get_fill_volumes → list_part_categories →
     * list_articles. Fewer hops and one tool result keep the agent loop short and its context small.
     */
    private const EXCLUDED = [
        'list_makes',
        'list_models',
        'get_fill_volumes',
        'list_part_categories',
        'list_articles',
        // The pilot channel ({@see \App\ApiResource\Dashboard}) — the dashboard render intents exposed
        // to EXTERNAL MCP hosts. The agent already has each of them as an `#[AsTool]` bound to the
        // asking tab's session topic; the MCP twins publish to the owner's pilot topic instead, so
        // leaving them visible here would both duplicate the tool names and mis-route the frames.
        'select_vehicle',
        'toast',
        'show_fact',
        'focus_view',
        'propose_actions',
        'narrate_step',
        'settle_dashboard',
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
            if (\in_array($definition['name'], self::EXCLUDED, true)) {
                continue;
            }

            $tools[] = new Tool(
                new ExecutionReference(self::class, 'execute'),
                $definition['name'],
                $definition['description'] ?? '',
                $definition['inputSchema'] ?? null,
            );
        }

        // Memoise ONLY a non-empty catalog. An empty list means the in-process handshake or the
        // tools/list call failed (the garage server always exposes tools), so [] is a transient
        // failure, not a valid answer — caching it would strand this long-lived worker tool-less for
        // its whole lifetime. Leaving $this->tools null lets the next turn re-fetch and self-heal.
        if ([] !== $tools) {
            $this->tools = $tools;
        }

        return $tools;
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
            // A genuine unknown-tool is METHOD_NOT_FOUND; anything else (INTERNAL_ERROR from a handler
            // that threw — e.g. a catalog upstream 401/timeout — or INVALID_PARAMS) means the tool
            // exists but its execution failed. Surface that as a recoverable ToolExecutionException so
            // FaultTolerantToolbox hands the model an error to recover from, instead of a "tool not
            // found" lie that drops the tool, or a raw exception that aborts the whole turn.
            if (Error::METHOD_NOT_FOUND === ($response['error']['code'] ?? null)) {
                throw ToolNotFoundException::notFoundForToolCall($toolCall);
            }

            throw ToolExecutionException::executionFailed(
                $toolCall,
                new \RuntimeException((string) ($response['error']['message'] ?? 'tool execution failed')),
            );
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
    protected function drive(array $request): array
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
