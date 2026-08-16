<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\I18n\AgentMessages;
use App\I18n\LocaleContext;

/**
 * Turns the agent's tool calls into the running narration the dashboard heartbeat shows ("Je consulte
 * le catalogue…" / "Checking the parts catalogue…"). The sentences are DERIVED from the tool the loop
 * is about to run, not asked of the model — so they cost no tool call, no tokens, and cannot feed the
 * runaway loop (publish-only side effect, never a tool result). Wired into
 * {@see \App\Agent\Toolbox\CompositeToolbox} for the in-process garage tools, and called with
 * `web_search` from the stream budget listener for Anthropic's server-side search (the one step that
 * is not a local tool call).
 *
 * The wording lives in {@see AgentMessages}, keyed by tool. The tool NAME also rides the frame, so the
 * dashboard's step chip is keyed on the identity rather than on the wording — which is what lets the
 * sentence be written in any language without the chips degrading.
 *
 * An unmapped tool stays silent on purpose: a tool with no user-meaningful step (or a newly added
 * one) simply produces no line rather than leaking a raw tool name to the user.
 */
final readonly class StepNarrator
{
    /**
     * The tools that narrate. Declared here so a catalog can be checked against it — a tool that
     * narrates in one language and is silent in the other reads as a dropped step, not as a missing
     * translation.
     *
     * @var list<string>
     */
    public const array TOOLS = [
        'list_vehicles',
        'lookup_plate',
        'get_service_parts',
        'get_motul_oil',
        'plan_maintenance',
        'update_vehicle',
        'log_maintenance',
        'get_vehicle_facts',
        'get_maintenance_history',
        'list_due_maintenance',
        'web_search',
    ];

    public function __construct(
        private RenderIntentTools $tools,
        private LocaleContext $locale,
    ) {
    }

    public function forTool(string $toolName): void
    {
        $sentence = AgentMessages::for($this->locale->current())->narration[$toolName] ?? null;

        if (null !== $sentence) {
            // The tool name goes on the frame beside the sentence: the sentence is prose, the name is
            // the identity the dashboard's step chip is keyed on.
            $this->tools->narrate($sentence, $toolName);
        }
    }
}
