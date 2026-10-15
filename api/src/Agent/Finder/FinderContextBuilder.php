<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Message\RunAgentTurn;

/**
 * The user message of a finder turn: the car the page already identified, the request, and — when the
 * turn answers an ask_question — the question it answers. Turns stay stateless; that breadcrumb is the
 * only memory carried across them.
 */
final class FinderContextBuilder
{
    public function build(RunAgentTurn $turn): string
    {
        $vehicle = $turn->vehicle;
        $context = '';

        if (null !== $vehicle) {
            $identity = array_filter([$vehicle->make, $vehicle->model, $vehicle->engine]);
            $context .= \sprintf(
                "Véhicule (déjà identifié, ne le redemande pas) : %s — carId %d, carType %s.\n\n",
                [] === $identity ? 'inconnu' : implode(' ', $identity),
                $vehicle->carId,
                $vehicle->carType->value,
            );
        }

        if ('' !== $turn->pendingContext) {
            $context .= "Tu viens de poser une question ; la demande ci-dessous est la réponse de l'utilisateur."
                ."\nContexte de ta question : ".$turn->pendingContext."\n\n";
        }

        return $context.'Demande : '.$turn->instruction;
    }
}
