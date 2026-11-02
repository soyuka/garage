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
        'list_part_categories',
    ];

    /**
     * Ids announced this turn — from the stream (early) or execute() (fallback). A provider issues a
     * fresh id per tool call, never reused across turns, so this only needs {@see reset()} per turn,
     * not an eviction policy.
     *
     * @var array<string, true>
     */
    private array $announced = [];

    /**
     * Last variant index served per tool, this turn — so a tool narrated twice in a row (a 7-call
     * `plan_maintenance` message; a second history read) does not repeat the same wording. Cleared
     * in {@see reset()}, same lifetime as $announced.
     *
     * @var array<string, int>
     */
    private array $lastIndex = [];

    /**
     * PHP has no way to write a closure as a constructor default (it is not a constant expression),
     * so the random picker is applied in {@see sentence()} instead of at the parameter — `null` here
     * means "use `random_int`", and autowiring is untouched by it (no service of type `Closure` is
     * ever asked for).
     *
     * @param \Closure(int $count): int|null $picker
     */
    public function __construct(
        private readonly RenderIntentTools $tools,
        private readonly LocaleContext $locale,
        private readonly ?\Closure $picker = null,
    ) {
    }

    public function forTool(string $toolName): void
    {
        $sentence = $this->sentence($toolName);

        if (null !== $sentence) {
            // The tool name goes on the frame beside the sentence: the sentence is prose, the name is
            // the identity the dashboard's step chip is keyed on.
            $this->tools->narrate($sentence, $toolName);
        }
    }

    /**
     * Picks one variant for $tool, favouring one different from the last index served for that same
     * tool this turn. The picker is asked once; if it lands back on the last index, the pick is
     * shifted deterministically to the next variant rather than re-asking it — a picker that always
     * returns the same index (a test double, or bad luck from `random_int`) must still never repeat.
     */
    private function sentence(string $tool): ?string
    {
        $messages = AgentMessages::for($this->locale->current());
        $variants = $messages->narration[$tool] ?? null;

        if (null === $variants) {
            return null;
        }

        $count = \count($variants);
        $pick = $this->picker ?? static fn (int $n): int => random_int(0, $n - 1);
        $index = $pick($count);

        if ($count > 1 && $index === ($this->lastIndex[$tool] ?? null)) {
            $index = ($index + 1) % $count;
        }

        $this->lastIndex[$tool] = $index;

        return $variants[$index];
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

    /**
     * The model-authored twin: publishes `narrate_step`'s own sentence (no tool name — see
     * {@see RenderIntentTools::narrateStep()}) and records the id as announced.
     */
    public function announceStep(string $toolCallId, string $message): void
    {
        $this->announced[$toolCallId] = true;
        $this->tools->narrate($message);
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
        $this->lastIndex = [];
    }
}
