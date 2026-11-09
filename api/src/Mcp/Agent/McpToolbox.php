<?php

declare(strict_types=1);

namespace App\Mcp\Agent;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use Mcp\Server\Stateless\RequestMeta;
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
 * It speaks the stateless 2026-07-28 revision, the path modern clients take: no `initialize`, every
 * request carries its protocol version and client capabilities in `_meta`, one round-trip through an
 * {@see InProcessTransport}.
 */
class McpToolbox implements ToolboxInterface
{
    /**
     * Tools withheld from the agent. The filter is a DENYLIST on purpose: every garage MCP tool is
     * meant for the agent, so it fails OPEN — a newly added tool surfaces to the agent automatically
     * and only an explicit entry here hides it. (An allowlist failed closed and silently stripped
     * update_vehicle/plan_maintenance when 586b223 added them without updating this list — the prompt
     * then commanded tools the agent could not see, so the usage write and the checklist no-op'd.).
     *
     * Excluded from the garage agent: the make/model browse roots (lookup_plate / list_cars resolve the
     * carId directly). list_part_categories / list_articles stay
     * visible: the service flow uses the single get_service_parts call, but brand and reference
     * questions the open tasks cannot answer are routed to the catalog walk by the system prompt, so
     * the agent must be able to call it.
     */
    private const BROWSE_ROOTS = [
        'list_makes',
        'list_models',
    ];

    private const EXCLUDED = [
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
        'reveal_assembly',
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

        $response = $this->drive(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);

        $result = $response['result'] ?? null;
        $definitions = \is_array($result) && \is_array($result['tools'] ?? null) ? $result['tools'] : [];

        $tools = [];
        foreach ($definitions as $definition) {
            if (!\is_array($definition) || !\is_string($definition['name'] ?? null)) {
                continue;
            }
            if (\in_array($definition['name'], self::EXCLUDED, true)) {
                continue;
            }
            if (\in_array($definition['name'], self::BROWSE_ROOTS, true)) {
                continue;
            }
            $description = $definition['description'] ?? '';
            $inputSchema = $definition['inputSchema'] ?? null;

            $tools[] = new Tool(
                new ExecutionReference(self::class, 'execute'),
                $definition['name'],
                \is_string($description) ? $description : '',
                // @mago-expect analysis:possibly-invalid-argument the JSON Schema this same server advertises for the tool
                \is_array($inputSchema) ? $inputSchema : null,
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

        $error = $response['error'] ?? null;
        if (null !== $error) {
            // A genuine unknown-tool is METHOD_NOT_FOUND; anything else (INTERNAL_ERROR from a handler
            // that threw — e.g. a catalog upstream 401/timeout) means the tool
            // exists but its execution failed. Surface that as a recoverable ToolExecutionException so
            // FaultTolerantToolbox hands the model an error to recover from, instead of a "tool not
            // found" lie that drops the tool, or a raw exception that aborts the whole turn.
            if (\is_array($error) && Error::METHOD_NOT_FOUND === ($error['code'] ?? null)) {
                throw ToolNotFoundException::notFoundForToolCall($toolCall);
            }
            $message = \is_array($error) && \is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'tool execution failed';

            throw ToolExecutionException::executionFailed($toolCall, new \RuntimeException($message));
        }

        $result = $response['result'] ?? [];
        if (!\is_array($result)) {
            throw ToolExecutionException::executionFailed($toolCall, new \RuntimeException('tools/call answered without a result object'));
        }

        // A tool execution error stays a flagged CallToolResult: the model reads `isError` beside the
        // text, and RepeatedToolFailureGuard counts it.
        if ($result['isError'] ?? false) {
            return new ToolResult($toolCall, CallToolResult::fromArray($result));
        }

        // Prefer the structured payload (what the dashboard / tests rely on); fall back to text.
        $content = $result['content'] ?? [];
        $texts = [];
        foreach (\is_array($content) ? $content : [] as $block) {
            if (\is_array($block) && \is_scalar($block['text'] ?? null)) {
                $texts[] = (string) $block['text'];
            }
        }
        $payload = $result['structuredContent'] ?? implode("\n", $texts);

        return new ToolResult($toolCall, $payload);
    }

    /**
     * Run the DI MCP server in-process for one request, stamped with the modern-era `_meta` envelope.
     *
     * @param array{jsonrpc: string, id: int, method: string, params: array<string, mixed>} $request
     *
     * @return array<array-key, mixed> the decoded JSON-RPC response to $request
     */
    protected function drive(array $request): array
    {
        $request['params']['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
            RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
            RequestMeta::CLIENT_INFO => ['name' => 'in-process-agent', 'version' => '0.0.0'],
        ];

        return $this->server->run(new InProcessTransport($request));
    }
}
