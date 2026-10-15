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
 *
 * Each tool narrates through 3–4 variants (operator feedback 2026-09-05: the fixed one-liner reads as
 * repetitive over a long turn) — same register, same meaning, different wording, picked by
 * {@see \App\Agent\Ui\StepNarrator}. `get_vehicle_facts` and `get_maintenance_history` used to share
 * the exact same sentence; they are now deliberately distinct (the spec sheet vs. the logbook).
 */
final readonly class AgentMessages
{
    /**
     * @param array<string, non-empty-list<string>> $narration tool name → the heartbeat sentence variants for that step
     */
    private function __construct(
        public array $narration,
        public string $noOp,
        public string $notConverged,
        public string $cancelled,
        public string $finderNoOp,
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
                'list_vehicles' => [
                    'Je consulte ton garage…',
                    'Je fais le tour du garage…',
                    'Je vérifie les véhicules enregistrés…',
                    'Je parcours la liste des véhicules…',
                ],
                'lookup_plate' => [
                    'Je lis la plaque…',
                    'Je déchiffre la plaque…',
                    "Je vérifie l'immatriculation…",
                    'Je recherche par plaque…',
                ],
                'get_service_parts' => [
                    'Je consulte le catalogue de pièces…',
                    'Je feuillette le catalogue…',
                    'Je cherche la référence…',
                    'Je vérifie les pièces disponibles…',
                ],
                'get_motul_oil' => [
                    'Je vérifie la préconisation Motul…',
                    'Je consulte la fiche Motul…',
                    'Je cherche la viscosité recommandée…',
                ],
                'plan_maintenance' => [
                    "J'établis le programme d'entretien…",
                    "Je prépare le plan d'entretien…",
                    "J'organise les prochaines interventions…",
                    "Je mets en ordre le calendrier d'entretien…",
                ],
                'update_vehicle' => [
                    'Je mets à jour le véhicule…',
                    'J\'actualise la fiche du véhicule…',
                    'Je corrige les informations du véhicule…',
                ],
                'log_maintenance' => [
                    "J'enregistre l'intervention…",
                    'Je note la visite…',
                    "J'inscris l'intervention au carnet…",
                    'Je consigne les travaux effectués…',
                ],
                'get_vehicle_facts' => [
                    'Je relis la fiche technique…',
                    'Je vérifie les caractéristiques…',
                    'Je consulte les données du véhicule…',
                    'Je reprends la fiche du véhicule…',
                ],
                'get_maintenance_history' => [
                    "Je relis l'historique…",
                    'Je reprends le carnet…',
                    'Je remonte les visites…',
                    "Je feuillette l'entretien passé…",
                ],
                'list_due_maintenance' => [
                    'Je calcule les entretiens à venir…',
                    "Je vérifie ce qui arrive à échéance…",
                    'Je fais le point sur les échéances…',
                    "J'évalue les prochaines interventions…",
                ],
                'web_search' => [
                    'Je cherche en ligne…',
                    'Je fais une recherche…',
                    'Je vérifie sur le web…',
                ],
                'list_part_categories' => [
                    'Je cherche la bonne catégorie…',
                    "Je parcours l'arbre des pièces…",
                    'Je repère la pièce au catalogue…',
                ],
                'show_parts' => [
                    "J'affine les références…",
                    'Je compare les pièces compatibles…',
                    'Je trie les références…',
                ],
            ],
            noOp: "Je n'ai pas pu traiter ta demande. Reformule ou précise ce que tu veux enregistrer.",
            notConverged: "Je n'ai pas pu terminer cette demande. Reformule ou précise ta question.",
            cancelled: 'Demande interrompue.',
            finderNoOp: "Je n'ai trouvé aucune pièce pour cette demande. Reformule-la, ou choisis une catégorie dans la liste.",
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
                'list_vehicles' => [
                    'Checking your garage…',
                    'Taking a look around the garage…',
                    'Checking the registered vehicles…',
                    'Going through the vehicle list…',
                ],
                'lookup_plate' => [
                    'Reading the plate…',
                    'Making out the plate…',
                    'Checking the registration…',
                    'Looking it up by plate…',
                ],
                'get_service_parts' => [
                    'Checking the parts catalogue…',
                    'Flipping through the catalogue…',
                    'Looking up the reference…',
                    'Checking what parts are available…',
                ],
                'get_motul_oil' => [
                    'Checking the Motul recommendation…',
                    'Checking the Motul spec sheet…',
                    'Looking up the recommended viscosity…',
                ],
                'plan_maintenance' => [
                    'Drawing up the service plan…',
                    'Putting together the maintenance plan…',
                    'Organising the next jobs…',
                    'Sorting out the maintenance calendar…',
                ],
                'update_vehicle' => [
                    'Updating the vehicle…',
                    'Refreshing the vehicle record…',
                    'Correcting the vehicle details…',
                ],
                'log_maintenance' => [
                    'Recording the service…',
                    'Noting the visit…',
                    'Logging the job in the book…',
                    'Writing up the work done…',
                ],
                'get_vehicle_facts' => [
                    'Rereading the spec sheet…',
                    'Checking the specifications…',
                    'Checking the vehicle data…',
                    'Going back over the vehicle sheet…',
                ],
                'get_maintenance_history' => [
                    'Rereading the history…',
                    'Going back through the logbook…',
                    'Going back over the visits…',
                    'Flipping through past maintenance…',
                ],
                'list_due_maintenance' => [
                    'Working out what is due…',
                    "Checking what's coming up…",
                    "Taking stock of what's due…",
                    'Assessing the next jobs…',
                ],
                'web_search' => [
                    'Searching online…',
                    'Running a search…',
                    'Checking the web…',
                ],
                'list_part_categories' => [
                    'Finding the right category…',
                    'Walking the parts tree…',
                    'Locating the part in the catalogue…',
                ],
                'show_parts' => [
                    'Narrowing the references…',
                    'Comparing the fitting parts…',
                    'Sorting the references…',
                ],
            ],
            noOp: 'I could not handle that. Rephrase it, or say more precisely what you want recorded.',
            notConverged: 'I could not finish that. Rephrase it, or narrow the question.',
            cancelled: 'Request stopped.',
            finderNoOp: 'I found no part for that request. Rephrase it, or pick a category from the list.',
        );
    }
}
