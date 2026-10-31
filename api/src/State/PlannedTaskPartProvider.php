<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlannedTaskPart;
use App\Maintenance\OwnedPlannedTaskLocator;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/planned-tasks/{taskId}/parts/{id} by loading the owning task and locating the
 * embedded line by its ULID. Backs the Patch (the merge-patch deserializes onto the resource this
 * returns, then the processor writes it back). Returns null → 404 when the task or the line is absent.
 *
 * @implements ProviderInterface<PlannedTaskPart>
 */
final readonly class PlannedTaskPartProvider implements ProviderInterface
{
    public function __construct(
        private OwnedPlannedTaskLocator $locator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PlannedTaskPart
    {
        $taskId = $uriVariables['taskId'];
        $task = $this->locator->find($taskId instanceof Ulid ? $taskId : Ulid::fromString((string) $taskId));
        if (null === $task) {
            return null;
        }

        $id = $uriVariables['id'];
        $partId = $id instanceof Ulid ? $id : Ulid::fromString((string) $id);
        $part = $task->findPart($partId);
        if (null === $part) {
            return null;
        }

        return PlannedTaskPart::fromArray($part->toArray(), $task->getId());
    }
}
