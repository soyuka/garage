<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mercure\DomainPublisher;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/planned-tasks/{taskId}/parts/{id} — drop one shopping-list line by its ULID. 404
 * when the task or the line is gone, then flush + Mercure fan-out so the checklist live-updates.
 *
 * @implements ProcessorInterface<mixed, void>
 */
final class PlannedTaskPartDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly DocumentManager $em,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $taskId = $uriVariables['taskId'];
        $task = $this->tasks->find($taskId instanceof Ulid ? $taskId : Ulid::fromString((string) $taskId));
        if (null === $task) {
            throw new NotFoundHttpException('No planned task at this IRI.');
        }

        $id = $uriVariables['id'];
        if (!$task->removePart($id instanceof Ulid ? $id : Ulid::fromString((string) $id))) {
            throw new NotFoundHttpException('No part at this IRI.');
        }

        $this->em->flush();
        $this->publisher->publishVehicle($task->getVehicle());
    }
}
