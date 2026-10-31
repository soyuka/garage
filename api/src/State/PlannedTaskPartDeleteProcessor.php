<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Maintenance\OwnedPlannedTaskLocator;
use App\Maintenance\PlannedPartRemover;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/planned-tasks/{taskId}/parts/{id} — drop one shopping-list line by its ULID. 404
 * when the task or the line is gone.
 *
 * @implements ProcessorInterface<mixed, void>
 */
final readonly class PlannedTaskPartDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private OwnedPlannedTaskLocator $locator,
        private PlannedPartRemover $remover,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $taskId = $uriVariables['taskId'];
        $task = $this->locator->find($taskId instanceof Ulid ? $taskId : Ulid::fromString((string) $taskId));
        if (null === $task) {
            throw new NotFoundHttpException('No planned task at this IRI.');
        }

        $id = $uriVariables['id'];
        if (!$this->remover->remove($task, $id instanceof Ulid ? $id : Ulid::fromString((string) $id))) {
            throw new NotFoundHttpException('No part at this IRI.');
        }
    }
}
