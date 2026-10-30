<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Agent\TurnSurface;
use App\I18n\AgentMessages;
use App\I18n\LocaleContext;

/**
 * Turns the agent's tool calls into the one-line status the page shows while the loop runs
 * ("Lecture de la plaque…" / "Reading the plate…"). The sentences are DERIVED from the tool the loop
 * is about to run, not asked of the model — so they cost no tool call, no tokens, and cannot feed the
 * runaway loop (publish-only side effect, never a tool result). Wired into
 * {@see \App\Agent\Toolbox\CompositeToolbox} for the in-process catalogue tools, and called with
 * `web_search` from the stream budget listener for Anthropic's server-side search (the one step that
 * is not a local tool call).
 *
 * The wording lives in {@see AgentMessages}, keyed by tool. The tool NAME also rides the frame, so the
 * page's step chip is keyed on the identity rather than on the wording.
 *
 * An unmapped tool stays silent on purpose: a tool with no user-meaningful step (or a newly added
 * one) simply produces no line rather than leaking a raw tool name to the user.
 *
 * Also remembers, per tool-call id, which calls {@see EarlyStepNarrationListener} already narrated
 * from the stream (at `ToolCallStart`/completed `ToolInputDelta`, well before the call executes) —
 * {@see \App\Agent\Toolbox\CompositeToolbox} checks {@see wasAnnounced()} so the execute-time path
 * narrates only a call the stream never announced (a bridge that emits no `ToolCallStart`).
 */
final class StepNarrator
{
    /**
     * The tools that narrate. Declared here so a catalog can be checked against it — a tool that
     * narrates in one language and is silent in the other reads as a dropped step, not as a missing
     * translation.
     *
     * @var list<string>
     */
    public const array TOOLS = [
        'lookup_plate',
        'decode_vin',
        'list_makes',
        'list_models',
        'list_cars',
        'decode_build_code',
        'set_build_code',
        'list_part_categories',
        'get_service_parts',
        'show_parts',
        'web_search',
    ];

    /**
     * Ids announced this turn — from the stream (early) or execute() (fallback). A provider issues a
     * fresh id per tool call, never reused across turns, so this only needs {@see reset()} per turn,
     * not an eviction policy.
     *
     * @var array<string, true>
     */
    private array $announced = [];

    public function __construct(
        private readonly RenderIntentTools $tools,
        private readonly LocaleContext $locale,
    ) {
    }

    public function forTool(string $toolName): void
    {
        $sentence = AgentMessages::for($this->locale->current())->finderNarration[$toolName] ?? null;

        if (null !== $sentence) {
            $this->tools->narrate($sentence, $toolName);
        }
    }

    /**
     * The early twin of {@see forTool()}: same lookup, same silence for an unmapped tool, but keyed
     * on the tool-call id so a later {@see wasAnnounced()} check can skip re-narrating at execute
     * time. The id is recorded even when the tool is unmapped (and so produces no sentence) — an
     * unmapped tool must stay silent at execute time too, not just here.
     */
    public function announce(string $toolCallId, string $toolName): void
    {
        $this->announced[$toolCallId] = true;
        $this->forTool($toolName);
    }

    public function wasAnnounced(string $toolCallId): bool
    {
        return isset($this->announced[$toolCallId]);
    }

    /**
     * Called once per turn by {@see \App\MessageHandler\RunAgentTurnHandler}, alongside the other
     * per-turn resets — this is a long-lived singleton in the worker.
     */
    public function reset(): void
    {
        $this->announced = [];
    }
}
