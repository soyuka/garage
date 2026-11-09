<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells the dashboard which tool the agent is running, so its step chip, running action and orb can
 * key on it. It publishes the tool key alone, never a sentence: the ticker carries only what the model
 * chose to say (`narrate_step`, `toast`) — owner decision 2026-11-09, the canned per-tool lines were noise.
 * Wired into {@see \App\Agent\Toolbox\CompositeToolbox} for the in-process garage tools, and called with
 * `web_search` from the stream budget listener for Anthropic's server-side search.
 *
 * Also remembers, per tool-call id, which calls {@see EarlyStepNarrationListener} already announced
 * from the stream (at `ToolCallStart`, well before the call executes) — {@see \App\Agent\Toolbox\CompositeToolbox}
 * checks {@see wasAnnounced()} so the execute-time path signals only a call the stream never announced
 * (a bridge that emits no `ToolCallStart`), and an already-published `narrate_step` is not run twice.
 */
final class StepNarrator implements ResetInterface
{
    /**
     * The tools that raise a step chip — the dashboard's `CHIP` table (`dashboard/src/noyau/state.ts`)
     * knows each one. Any other tool stays silent rather than leaking a raw tool name to the operator.
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
     * fresh id per tool call, never reused across turns, so this only needs {@see reset()} per turn.
     *
     * @var array<string, true>
     */
    private array $announced = [];

    public function __construct(
        private readonly RenderIntentTools $tools,
    ) {
    }

    public function forTool(string $toolName): void
    {
        if (\in_array($toolName, self::TOOLS, true)) {
            $this->tools->step($toolName);
        }
    }

    /** The early twin of {@see forTool()}: the id is recorded even for a silent tool, so execute time stays silent too. */
    public function announce(string $toolCallId, string $toolName): void
    {
        $this->announced[$toolCallId] = true;
        $this->forTool($toolName);
    }

    /** Publishes `narrate_step`'s own sentence (see {@see RenderIntentTools::narrateStep()}) and records the id. */
    public function announceStep(string $toolCallId, string $message): void
    {
        $this->announced[$toolCallId] = true;
        $this->tools->narrate($message);
    }

    public function wasAnnounced(string $toolCallId): bool
    {
        return isset($this->announced[$toolCallId]);
    }

    public function reset(): void
    {
        $this->announced = [];
    }
}
