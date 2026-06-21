<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Caps the garage agent's tool-call loop. Symfony AI's ToolProcessor (ai.tool.agent_processor.<name>)
 * loops "model → tool calls → feed results → model" until the model returns a plain text answer with
 * no tool calls; its sixth constructor arg (maxToolCalls) defaults to null = UNBOUNDED. A model that
 * keeps emitting render-intent tools (propose_actions/toast) never terminates — which is exactly the
 * runaway that spun ~26 passes, ballooned the prompt to 25k tokens and burned the provider quota.
 *
 * The bundle exposes no config knob for it, and the abstract parent defines only args 0-4, so we
 * append arg 5 here. Runs after ResolveChildDefinitionsPass (default optimization phase), so the
 * service is a flattened Definition with args 0-4 present and 5 is the next index to set.
 *
 * This is the HARD backstop; the normal exit is {@see \App\Agent\Ui\TerminalProposeActionsListener},
 * which ends the turn as soon as a CTA is rendered.
 */
final class AgentMaxToolCallsPass implements CompilerPassInterface
{
    /** Generous headroom over the longest legitimate chain (read facts → motul → plan → render). */
    private const MAX_TOOL_CALLS = 25;

    public function process(ContainerBuilder $container): void
    {
        $id = 'ai.tool.agent_processor.garage';
        if (!$container->hasDefinition($id)) {
            return;
        }

        $container->getDefinition($id)->setArgument(5, self::MAX_TOOL_CALLS);
    }
}
