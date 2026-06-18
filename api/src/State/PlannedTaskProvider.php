<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlannedTask;
use App\Repository\PlannedMaintenanceRepository;
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
    public function __construct(private readonly PlannedMaintenanceRepository $tasks)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PlannedTask
    {
        $id = $uriVariables['id'];
        $task = $this->tasks->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        return null === $task ? null : PlannedTask::fromEntity($task);
    }
}
