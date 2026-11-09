<?php

declare(strict_types=1);

namespace App\Agent\Toolbox;

use App\Agent\Ui\StepNarrator;
use App\Capability\Capabilities;
use App\Capability\RequiresCapability;
use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Gives the garage agent both tool families through one {@see ToolboxInterface} — the bundle wires
 * exactly one toolbox per agent and the {@see \Symfony\AI\Agent\Toolbox\AgentProcessor} takes one.
 * It decorates `ai.toolbox.garage` (the bundle's `#[AsTool]` toolbox holding the render-intent
 * tools) and merges in the in-process garage tools from {@see McpToolbox}.
 *
 * Execution routes by ownership: a call whose name belongs to the MCP catalog goes to the MCP
 * bridge, everything else to the decorated render toolbox.
 *
 * This is also the one seam that sees every garage tool the agent runs — the MCP bridge bypasses the
 * bundle Toolbox that dispatches ToolCallRequested — so it is where the step frame is emitted: before
 * delegating an MCP call, {@see StepNarrator} tells the dashboard which tool runs (its key, no
 * sentence). Render-intent calls raise no step (they ARE the UI, not a work step).
 *
 * The step here is the FALLBACK: {@see \App\Agent\Ui\EarlyStepNarrationListener} announces most
 * calls from the stream, well before they reach this method — see
 * {@see StepNarrator::wasAnnounced()}. An MCP call signals here only if the stream never announced it
 * (a bridge that emits no `ToolCallStart`). `narrate_step` is the mirror case: if the stream already
 * published its sentence, executing it again here would push a duplicate pulse/step chip through the
 * dashboard reducer, so an already-announced call just acks without running.
 *
 * An `#[AsTool]` class marked {@see RequiresCapability} is withheld while its capability is not
 * configured (`web_search` without the selected backend's key): neither offered nor executable. MCP
 * tools are gated upstream, in the metadata the MCP server lists.
 */
final class CompositeToolbox implements ToolboxInterface
{
    /** @var array<string, true>|null cache of MCP tool names */
    private ?array $mcpNames = null;

    /** @var array<class-string, RequiresCapability|null> */
    private array $requirements = [];

    public function __construct(
        private readonly ToolboxInterface $renderToolbox,
        private readonly McpToolbox $mcpToolbox,
        private readonly StepNarrator $narrator,
        private readonly Capabilities $capabilities,
    ) {
    }

    public function getTools(): array
    {
        return array_merge($this->mcpToolbox->getTools(), $this->renderTools());
    }

    /**
     * @return list<Tool>
     */
    private function renderTools(): array
    {
        return array_values(array_filter($this->renderToolbox->getTools(), $this->available(...)));
    }

    private function withheld(string $name): bool
    {
        foreach ($this->renderToolbox->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return !$this->available($tool);
            }
        }

        return false;
    }

    private function available(Tool $tool): bool
    {
        $class = $tool->getReference()->getClass();
        if (!\array_key_exists($class, $this->requirements)) {
            $attribute = class_exists($class) ? (new \ReflectionClass($class))->getAttributes(RequiresCapability::class)[0] ?? null : null;
            $this->requirements[$class] = $attribute?->newInstance();
        }

        return null === $this->requirements[$class] || $this->capabilities->has($this->requirements[$class]->capability);
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (!$this->ownsMcp($toolCall->getName())) {
            if ($this->withheld($toolCall->getName())) {
                throw ToolNotFoundException::notFoundForToolCall($toolCall);
            }

            if ('narrate_step' === $toolCall->getName() && $this->narrator->wasAnnounced($toolCall->getId())) {
                return new ToolResult($toolCall, 'ok');
            }

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
            $names = [];
            foreach ($this->mcpToolbox->getTools() as $tool) {
                $names[$tool->getName()] = true;
            }
            // An empty listing is a transient failure (the in-process server could not list), not a
            // server with no tools: caching it would route every garage tool to the render toolbox
            // for the life of the worker.
            if ([] === $names) {
                return false;
            }
            $this->mcpNames = $names;
        }

        return isset($this->mcpNames[$name]);
    }
}
