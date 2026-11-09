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
 * Exposes the catalogue MCP tools to the Symfony AI agent *in-process*, by driving the very same
 * DI {@see Server} the /mcp endpoint uses — no HTTP, no OAuth, no re-authored `#[AsTool]` schemas.
 * The agent is a trusted in-process caller, so the Casdoor gate on /mcp (a real trust boundary for
 * external hosts) does not apply here.
 *
 * It speaks the stateless 2026-07-28 revision, the path modern clients take: no `initialize`, every
 * request carries its protocol version and client capabilities in `_meta`, one round-trip through an
 * {@see InProcessTransport}.
 */
class McpToolbox implements ToolboxInterface
{
    /** @var list<Tool>|null */
    private ?array $tools = null;

    /**
     * @param list<string> $only an ALLOWLIST: the agent sees a fixed read-only subset, so a tool added
     *                           to the server later never reaches it unless named here
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $only,
    ) {
    }

    /**
     * @phpstan-impure memoises only a non-empty catalogue, so two calls can answer differently
     */
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
            if (!\is_array($definition) || !\is_string($definition['name'] ?? null) || !\in_array($definition['name'], $this->only, true)) {
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
        // tools/list call failed (the server always exposes tools), so [] is a transient
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

        // Prefer the structured payload (what the tests rely on); fall back to text.
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
