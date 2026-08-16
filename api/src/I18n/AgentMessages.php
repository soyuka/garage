<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Everything the agent says to the USER, in both languages.
 *
 * A hand-written catalog rather than symfony/translation, deliberately: this repo's composer setup
 * resolves two local path repositories under ~/forks that are not visible inside the php container,
 * so `composer require` cannot run here at all (verified 2026-08-17 — it aborts on
 * `PathRepository … does not exist` and reverts). The surface is a dozen sentences with one consumer
 * each; a typed catalog costs less than a dependency this repo cannot currently install, and it
 * mirrors `dashboard/src/i18n/` so both halves of the app read the same way.
 *
 * NOT in here: tool descriptions, tool-result `hint` strings and the system prompt. Those are
 * MODEL-facing, they are English already, and the model reading English while answering the user in
 * French is exactly what it does today.
 */
final readonly class AgentMessages
{
    /**
     * @param array<string, string> $narration tool name → the heartbeat sentence for that step
     */
    private function __construct(
        public array $narration,
        public string $noOp,
        public string $notConverged,
        public string $cancelled,
    ) {
    }

    public static function for(Locale $locale): self
    {
        return match ($locale) {
            Locale::Fr => self::french(),
            Locale::En => self::english(),
        };
    }

    private static function french(): self
    {
        return new self(
            narration: [
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
            ],
            noOp: "Je n'ai pas pu traiter ta demande. Reformule ou précise ce que tu veux enregistrer.",
            notConverged: "Je n'ai pas pu terminer cette demande. Reformule ou précise ta question.",
            cancelled: 'Demande interrompue.',
        );
    }

    /**
     * First person present, one clause, same register as the French — this is a mechanic's workshop
     * narrating what it is doing, not an assistant announcing itself.
     */
    private static function english(): self
    {
        return new self(
            narration: [
                'list_vehicles' => 'Checking your garage…',
                'lookup_plate' => 'Reading the plate…',
                'get_service_parts' => 'Checking the parts catalogue…',
                'get_motul_oil' => 'Checking the Motul recommendation…',
                'plan_maintenance' => 'Drawing up the service plan…',
                'update_vehicle' => 'Updating the vehicle…',
                'log_maintenance' => 'Recording the service…',
                'get_vehicle_facts' => 'Rereading the history…',
                'get_maintenance_history' => 'Rereading the history…',
                'list_due_maintenance' => 'Working out what is due…',
                'web_search' => 'Searching online…',
            ],
            noOp: 'I could not handle that. Rephrase it, or say more precisely what you want recorded.',
            notConverged: 'I could not finish that. Rephrase it, or narrow the question.',
            cancelled: 'Request stopped.',
        );
    }
}
