<?php

declare(strict_types=1);

namespace App\Agent\Toolbox;

use App\Agent\Ui\StepNarrator;
use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Gives the finder agent both tool families through one {@see ToolboxInterface} — the bundle wires
 * exactly one toolbox per agent and the {@see \Symfony\AI\Agent\Toolbox\AgentProcessor} takes one.
 * It decorates `ai.toolbox.finder` (the bundle's `#[AsTool]` toolbox holding the finder's own
 * tools) and merges in the in-process catalogue tools from {@see McpToolbox}.
 *
 * Execution routes by ownership: a call whose name belongs to the MCP catalog goes to the MCP
 * bridge, everything else to the decorated render toolbox.
 *
 * This is also the one seam that sees every catalogue tool the agent runs — the MCP bridge bypasses the
 * bundle Toolbox that dispatches ToolCallRequested — so it is where the running narration is emitted:
 * before delegating an MCP call, {@see StepNarrator} publishes its step sentence (in the turn language) to the
 * page's status line. Finder tool calls are NOT narrated here (they ARE the UI, not a work step).
 *
 * Narration here is now the FALLBACK: {@see \App\Agent\Ui\EarlyStepNarrationListener} announces most
 * calls from the stream, well before they reach this method — see
 * {@see StepNarrator::wasAnnounced()}. An MCP call narrates here only if the stream never announced it
 * (a bridge that emits no `ToolCallStart`).
 */
final class CompositeToolbox implements ToolboxInterface
{
    /** @var array<string, true>|null cache of MCP tool names */
    private ?array $mcpNames = null;

    public function __construct(
        private readonly ToolboxInterface $renderToolbox,
        private readonly McpToolbox $mcpToolbox,
        private readonly StepNarrator $narrator,
    ) {
    }

    public function getTools(): array
    {
        return array_merge($this->mcpToolbox->getTools(), $this->renderToolbox->getTools());
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (!$this->ownsMcp($toolCall->getName())) {
            return $this->renderToolbox->execute($toolCall);
        }

        if (!$this->narrator->wasAnnounced($toolCall->getId())) {
            $this->narrator->forTool($toolCall->getName());
        }

        return $this->mcpToolbox->execute($toolCall);
    }

    private function ownsMcp(string $name): bool
    {
        if (null === $this->mcpNames) {
            $this->mcpNames = [];
            foreach ($this->mcpToolbox->getTools() as $tool) {
                $this->mcpNames[$tool->getName()] = true;
            }
        }

        return isset($this->mcpNames[$name]);
    }
}
