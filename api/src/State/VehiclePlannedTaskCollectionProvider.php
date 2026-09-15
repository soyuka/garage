<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlannedTask;
use App\Maintenance\PlannedTaskFluidEnricher;
use App\Maintenance\VehicleFactsProjector;
use App\Repository\PlannedMaintenanceRepository;
use App\Repository\VehicleRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Lists a vehicle's checklist at /garage/vehicles/{vehicleId}/planned-tasks — the dashboard's
 * "Tâches & liste de courses" card reads it on select. Returns the Open AND Done PlannedTask
 * resources (each with its own tick-off IRI); done tasks ride along so the card renders them
 * greyed-out instead of vanishing on tick-off. The checklist is a first-class subresource rather
 * than an array embedded on the vehicle item. An unknown vehicle yields an empty collection (not a
 * 404): the vehicle dereferences fine, it simply has nothing planned.
 *
 * @implements ProviderInterface<PlannedTask>
 */
final class VehiclePlannedTaskCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly VehicleFactsProjector $facts,
        private readonly PlannedTaskFluidEnricher $fluids,
    ) {
    }

    /**
     * @return list<PlannedTask>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            return [];
        }

        // ?includeDismissed=true is the dashboard's "see all" toggle — soft-removed tasks ride along
        // beneath the live list so the user can review (and reopen) what was dropped.
        $includeDismissed = $operation->getParameters()?->get('includeDismissed')?->getValue();

        // The fluid line ("which oil, what quantity") is derived from the vehicle facts once and shared
        // across every task in the checklist.
        $facts = $this->facts->forVehicle($vehicle);

        return array_map(
            fn ($task): PlannedTask => PlannedTask::fromEntity(
                $task,
                $this->fluids->enrich($task->getType(), $task->getParts(), $facts),
            ),
            $this->tasks->checklistForVehicle($vehicle, filter_var($includeDismissed, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false),
        );
    }
}
