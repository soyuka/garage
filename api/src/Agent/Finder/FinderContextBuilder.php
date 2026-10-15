<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Message\RunAgentTurn;

/**
 * The user message of a finder turn: the car the page already identified (or the fact that none is
 * known yet), why the page routed the input to the agent, what the photo intake read, and — when the
 * turn answers an ask_question — the question it answers. Turns stay stateless; that breadcrumb is the
 * only memory carried across them.
 */
final class FinderContextBuilder
{
    public function build(RunAgentTurn $turn): string
    {
        $vehicle = $turn->vehicle;

        if (null !== $vehicle) {
            $identity = array_filter([$vehicle->make, $vehicle->model, $vehicle->engine]);
            $context = \sprintf(
                "Véhicule (déjà identifié, ne le redemande pas) : %s — carId %d, carType %s.\n\n",
                [] === $identity ? 'inconnu' : implode(' ', $identity),
                $vehicle->carId,
                $vehicle->carType->value,
            );
        } else {
            $context = "Aucun véhicule identifié : identifie-le d'abord depuis la demande (plaque, marque, modèle, motorisation), "
                ."puis appelle set_vehicle avant show_parts. S'il manque de quoi le trouver, demande la plaque avec ask_question.\n\n";
        }

        $context .= match ($turn->intent) {
            FinderIntent::Reference => "La demande a la forme d'une référence de pièce : identifie la pièce que cette référence désigne.\n\n",
            FinderIntent::Photo => \sprintf("L'utilisateur a photographié la pièce (%d image(s) jointe(s)) : lis d'abord les références imprimées, puis l'aspect.\n\n", max(1, \count($turn->images))),
            FinderIntent::Text => [] === $turn->images ? '' : \sprintf("%d image(s) jointe(s) à la demande.\n\n", \count($turn->images)),
        };

        if (null !== $turn->photoHints && !$turn->photoHints->isEmpty()) {
            $hints = $turn->photoHints;
            $context .= "Lecture préalable de la photo (indicative, à vérifier sur l'image) :";
            if (null !== $hints->familyGuess) {
                $context .= "\n- famille probable : ".$hints->familyGuess;
            }
            if ([] !== $hints->printedReferences) {
                $context .= "\n- références imprimées : ".implode(' ; ', $hints->printedReferences);
            }
            if (null !== $hints->brandGuess) {
                $context .= "\n- marque : ".$hints->brandGuess;
            }
            $context .= "\n\n";
        }

        if ('' !== $turn->pendingContext) {
            $context .= "Tu viens de poser une question ; la demande ci-dessous est la réponse de l'utilisateur."
                ."\nContexte de ta question : ".$turn->pendingContext."\n\n";
        }

        return $context.'Demande : '.$turn->instruction;
    }
}
