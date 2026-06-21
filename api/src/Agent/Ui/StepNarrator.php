<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * Turns the agent's tool calls into the running French narration the dashboard heartbeat shows
 * ("Je consulte le catalogue…"). The sentences are DERIVED from the tool the loop is about to run,
 * not asked of the model — so they cost no tool call, no tokens, and cannot feed the runaway loop
 * (publish-only side effect, never a tool result). Wired into {@see \App\Agent\Toolbox\CompositeToolbox}
 * for the in-process garage tools, and called with `web_search` from the stream budget listener for
 * Anthropic's server-side search (the one step that is not a local tool call).
 *
 * An unmapped tool stays silent on purpose: a tool with no user-meaningful step (or a newly added
 * one) simply produces no line rather than leaking a raw tool name to the user.
 */
final readonly class StepNarrator
{
    private const SENTENCES = [
        'list_vehicles' => 'Je consulte ton garage…',
        'lookup_plate' => 'Je lis la plaque…',
        'get_service_parts' => 'Je consulte le catalogue de pièces…',
        'get_motul_oil' => 'Je vérifie la préconisation Motul…',
        'plan_maintenance' => "J'établis le programme d'entretien…",
        'update_vehicle' => 'Je mets à jour le véhicule…',
        'log_maintenance' => "J'enregistre l'intervention…",
        'get_vehicle_facts' => "Je relis l'historique…",
        'get_maintenance_history' => "Je relis l'historique…",
        'list_due_maintenance' => 'Je calcule les entretiens à venir…',
        'web_search' => 'Je cherche en ligne…',
    ];

    public function __construct(private RenderIntentTools $tools)
    {
    }

    public function forTool(string $toolName): void
    {
        if (isset(self::SENTENCES[$toolName])) {
            $this->tools->narrate(self::SENTENCES[$toolName]);
        }
    }
}
