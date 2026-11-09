<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Knowledge\BuildCode\BuildCodeDecoders;
use App\Knowledge\BuildCode\InvalidBuildCode;
use App\Knowledge\MakeName;
use App\Message\RunAgentTurn;

/**
 * The user message of a finder turn: the car the page already identified (or the fact that none is
 * known yet), why the page routed the input to the agent, what the photo intake read, and — when the
 * turn answers an ask_question — the question it answers. Turns stay stateless; that breadcrumb is the
 * only memory carried across them. A known vehicle also brings its leaf categories, so the agent picks
 * by meaning; set_vehicle returns the same list when the agent identifies the car itself.
 */
final readonly class FinderContextBuilder
{
    private const int MAX_SUMMARY_LENGTH = 2000;

    public function __construct(
        private FinderCategoryList $categories,
        private BuildCodeDecoders $decoders,
    ) {
    }

    public function build(RunAgentTurn $turn): string
    {
        $vehicle = $turn->vehicle;

        if (null !== $vehicle) {
            $identity = array_filter([$vehicle->make, $vehicle->model, $vehicle->engine]);
            $context = \sprintf(
                "Véhicule (déjà identifié, ne le redemande pas) : %s — %scarId %d, carType %s.\n\n",
                [] === $identity ? 'inconnu' : implode(' ', $identity),
                null === $vehicle->plate ? '' : 'plaque '.$vehicle->plate.', ',
                $vehicle->carId,
                $vehicle->carType->value,
            );
            if (null !== $vehicle->organisationNumber) {
                $context = substr($context, 0, -1).$this->buildCodeLine($vehicle)."\n\n";
            }
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

        if (null !== $turn->buildLabel && !$turn->buildLabel->isEmpty()) {
            $read = array_filter([
                null === $turn->buildLabel->opr ? null : 'OPR '.$turn->buildLabel->opr,
                [] === $turn->buildLabel->printedCodes ? null : 'codes : '.implode(' ; ', $turn->buildLabel->printedCodes),
            ]);
            $context .= 'Vignette constructeur lue sur la photo (copie verbatim) : '.implode(' ; ', $read)
                ." — passe-la à set_build_code avant de choisir entre les pièces.\n\n";
        }

        $summary = trim($turn->summary);
        if ('' !== $summary) {
            $context .= "Résultat affiché à l'écran (la demande le poursuit) : ".mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH)."\n\n";
        }

        if ('' !== $turn->pendingContext) {
            $context .= "Tu viens de poser une question ; la demande ci-dessous est la réponse de l'utilisateur."
                ."\nContexte de ta question : ".$turn->pendingContext."\n\n";
        }

        if (null !== $vehicle) {
            $context .= "Catégories du catalogue pour ce véhicule (id + nom, sous [rubrique]) :\n"
                .$this->categories->forVehicle($vehicle->carId, $vehicle->carType)."\n\n";
        }

        return $context.'Demande : '.$turn->instruction;
    }

    private function buildCodeLine(FinderVehicle $vehicle): string
    {
        $date = null;
        $decoder = null === $vehicle->make ? null : $this->decoders->forMake(MakeName::normalize($vehicle->make));
        if (null !== $decoder) {
            try {
                $date = $decoder->decode((string) $vehicle->organisationNumber)->date->format('Y-m-d');
            } catch (InvalidBuildCode) {
                $date = null;
            }
        }

        return \sprintf(
            'Code de fabrication du véhicule (déjà décodé, ne le redemande pas) : OPR %s%s. Compare-le directement aux ruptures OPR des pièces : quand il tranche, sélectionne la variante (show_parts clusterId) sans poser la question.',
            $vehicle->organisationNumber,
            null === $date ? '' : ', fabriqué le '.$date,
        );
    }
}
