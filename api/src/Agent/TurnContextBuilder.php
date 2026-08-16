<?php

declare(strict_types=1);

namespace App\Agent;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\I18n\Locale;
use App\Maintenance\VehicleFactsProjector;
use App\Message\RunAgentTurn;
use App\Repository\PlannedMaintenanceRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Builds the per-turn context handed to the LLM. A turn is otherwise stateless — the worker carries
 * no conversation history (see {@see \App\MessageHandler\RunAgentTurnHandler}) — so without this the
 * agent starts every turn blind to the selected vehicle's stored facts and open checklist. It then
 * web-searches specs it already holds, and cannot resolve "marque la vidange faite" to a real task.
 *
 * So when a vehicle is selected we inline, server-side (authoritative, not the client snapshot):
 *  - its KNOWN facts, flagged NOT to re-search — kills the redundant web_search;
 *  - its OPEN tasks WITH their ids — so the agent references a task by id to close it via
 *    log_maintenance, or attaches a spec to the right item.
 */
final readonly class TurnContextBuilder
{
    public function __construct(
        private GarageResolver $garage,
        private VehicleFactsProjector $factsProjector,
        private PlannedMaintenanceRepository $tasks,
    ) {
    }

    public function build(RunAgentTurn $turn): string
    {
        $context = self::outputLanguage(Locale::resolve($turn->locale))
            ."Instruction de l'utilisateur : ".$turn->instruction;

        if ('' !== $turn->selectedId) {
            $context .= "\n\nVéhicule actuellement sélectionné dans le tableau de bord : ".$turn->selectedId;

            $vehicle = $this->resolve($turn->selectedId);
            if (null !== $vehicle) {
                $context .= $this->identity($vehicle).$this->knownFacts($vehicle).$this->openTasks($vehicle);
            }
        }

        if ('' !== $turn->summary) {
            $context .= "\nRésumé : ".$turn->summary;
        }

        if ('' !== $turn->pendingContext) {
            $context .= "\n\nCarte d'action que tu viens d'afficher à l'utilisateur — sa réponse, même"
                ." brève (un moteur, un nombre, « oui »), répond À CETTE QUESTION ; interprète-la dans"
                ." ce contexte plutôt que de repartir de zéro :\n  ".$turn->pendingContext;
        }

        return $context;
    }

    /**
     * Which language the agent must ANSWER in — every toast, chip label and narration it writes.
     *
     * It rides the per-turn context rather than the system prompt because the prompt is static config
     * while the locale is per request. The prompt's own instructions stay English either way: they are
     * model-facing, and a model reading English while writing French is exactly what it already does.
     *
     * First line of the context on purpose. It is an instruction about the OUTPUT, so it must not sit
     * below a wall of vehicle facts where a long context can bury it.
     */
    private static function outputLanguage(Locale $locale): string
    {
        $language = match ($locale) {
            Locale::Fr => 'French',
            Locale::En => 'English',
        };

        return \sprintf(
            "Write every user-facing string this turn in %s — toast messages, suggestion chips, fact"
            ." labels and any narration. This is the language of the interface the user is looking at."
            ." It does NOT change the tool names, the part references or the service-type keys, which"
            ." stay exactly as the tools define them.\n\n",
            $language,
        );
    }

    private function resolve(string $iri): ?Vehicle
    {
        $id = substr($iri, (int) strrpos($iri, '/') + 1);
        if (!Ulid::isValid($id)) {
            return null;
        }

        return $this->garage->resolveById(Ulid::fromString($id));
    }

    /**
     * Inlines the identifying spec (make/model/year/motor/finition) so get_motul_oil and web_search
     * pin the exact variant from the first turn, instead of calling list_vehicles to learn which
     * vehicle the bare IRI points to.
     */
    private function identity(Vehicle $vehicle): string
    {
        $parts = [$vehicle->getMake(), $vehicle->getModel()];
        if (null !== $vehicle->getYear()) {
            $parts[] = (string) $vehicle->getYear();
        }
        if (null !== $vehicle->getEngineVariant()) {
            $parts[] = 'finition '.$vehicle->getEngineVariant();
        }
        if (null !== $vehicle->getEngineCode()) {
            $parts[] = 'moteur '.$vehicle->getEngineCode();
        }
        if (null !== $vehicle->getPlate()) {
            $parts[] = 'plaque '.$vehicle->getPlate();
        }

        return "\nVéhicule : ".implode(', ', $parts);
    }

    private function knownFacts(Vehicle $vehicle): string
    {
        $facts = $this->factsProjector->forVehicle($vehicle);
        if ([] === $facts) {
            return '';
        }

        $lines = array_map(static function ($fact): string {
            $value = $fact->getValue().(null !== $fact->getUnit() ? ' '.$fact->getUnit() : '');
            $provenance = $fact->getSource().($fact->isVerified() ? ', vérifié' : '');

            return \sprintf('  - %s : %s [%s]', $fact->getKey(), $value, $provenance);
        }, $facts);

        return "\n\nFaits connus (déjà stockés — NE PAS les rechercher sur le web) :\n".implode("\n", $lines);
    }

    private function openTasks(Vehicle $vehicle): string
    {
        $open = array_values(array_filter(
            $this->tasks->checklistForVehicle($vehicle),
            static fn (PlannedMaintenance $t): bool => $t->isOpen(),
        ));
        if ([] === $open) {
            return '';
        }

        $lines = array_map(static function (PlannedMaintenance $task): string {
            $name = $task->getType().(null !== $task->getLabel() ? ' ('.$task->getLabel().')' : '');
            $target = null !== $task->getTargetUsageValue()
                ? ' — cible '.$task->getTargetUsageValue().' '.($task->getTargetUsageUnit()?->value ?? '')
                : '';

            return \sprintf('  - [%s] %s%s', $task->getId(), $name, rtrim($target));
        }, $open);

        return "\n\nTâches en cours (réfère-toi à l'id pour clore une tâche via log_maintenance) :\n".implode("\n", $lines);
    }
}
