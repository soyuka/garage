<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Everything the agent says to the USER, in both languages.
 *
 * A hand-written catalog rather than symfony/translation, deliberately: the surface is a dozen
 * sentences with one consumer each, and a typed catalog costs less than a dependency.
 *
 * NOT in here: tool descriptions, tool-result `hint` strings and the system prompt. Those are
 * MODEL-facing, they are English already, and the model reading English while answering the user in
 * French is exactly what it does today.
 */
final readonly class AgentMessages
{
    /**
     * @param array<string, string> $finderNarration tool name → the finder's one status line for that step
     */
    private function __construct(
        public string $cancelled,
        public string $finderNoOp,
        public array $finderNarration,
        public string $finderNotConverged,
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
            cancelled: 'Demande interrompue.',
            finderNoOp: 'Aucune pièce trouvée. Reformulez la demande ou choisissez une catégorie.',
            finderNarration: [
                'lookup_plate' => 'Lecture de la plaque…',
                'decode_vin' => 'Décodage du VIN…',
                'list_makes' => 'Recherche du constructeur…',
                'list_models' => 'Recherche du modèle…',
                'list_cars' => 'Recherche de la motorisation…',
                'decode_build_code' => 'Décodage du code de fabrication…',
                'set_build_code' => 'Lecture du code de fabrication…',
                'list_part_categories' => 'Recherche de la catégorie…',
                'get_service_parts' => 'Liste des pièces d’entretien…',
                'show_parts' => 'Affinage des références…',
                'web_search' => 'Recherche sur le web…',
            ],
            finderNotConverged: 'Recherche non aboutie. Précisez la pièce ou le véhicule.',
        );
    }

    /**
     * First person present, one clause, same register as the French — this is a mechanic's workshop
     * narrating what it is doing, not an assistant announcing itself.
     */
    private static function english(): self
    {
        return new self(
            cancelled: 'Request stopped.',
            finderNoOp: 'No part found. Rephrase the request or pick a category.',
            finderNarration: [
                'lookup_plate' => 'Reading the plate…',
                'decode_vin' => 'Decoding the VIN…',
                'list_makes' => 'Finding the make…',
                'list_models' => 'Finding the model…',
                'list_cars' => 'Finding the engine…',
                'decode_build_code' => 'Decoding the build code…',
                'set_build_code' => 'Reading the build code…',
                'list_part_categories' => 'Finding the category…',
                'get_service_parts' => 'Listing service parts…',
                'show_parts' => 'Narrowing the references…',
                'web_search' => 'Searching the web…',
            ],
            finderNotConverged: 'Search did not finish. Name the part or the vehicle more precisely.',
        );
    }
}
