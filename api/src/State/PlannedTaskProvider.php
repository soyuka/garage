<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlannedTask;
use App\Maintenance\OwnedPlannedTaskLocator;
use App\Maintenance\PlannedTaskFluidEnricher;
use App\Maintenance\VehicleFactsProjector;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/planned-tasks/{id} by loading the PlannedMaintenance behind the public ULID
 * and mapping it to the read-model. Returns null (→ 404) when the ULID matches no row. Backs both the
 * Get and the Patch (the Patch deserializes onto the resource this returns, then the processor runs).
 *
 * @implements ProviderInterface<PlannedTask>
 */
final class PlannedTaskProvider implements ProviderInterface
{
    public function __construct(
        private readonly OwnedPlannedTaskLocator $locator,
        private readonly VehicleFactsProjector $facts,
        private readonly PlannedTaskFluidEnricher $fluids,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PlannedTask
    {
        $id = $uriVariables['id'];
        $task = $this->locator->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $task) {
            return null;
        }

        $parts = $this->fluids->enrich(
            $task->getType(),
            $task->getParts(),
            $this->facts->forVehicle($task->getVehicle()),
        );

        return PlannedTask::fromEntity($task, $parts);
    }
}
