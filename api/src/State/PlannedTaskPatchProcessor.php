<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlannedTask;
use App\Maintenance\PlannedMaintenanceStatus;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * The dashboard tick-off write: flips a planned task's lifecycle (status is the only writable field).
 * "done" stamps completedAt; "dismissed" drops it without crediting the work; "open" reopens it. The
 * flush triggers DomainEventPublisher, so the checklist fans out over Mercure like any garage write.
 *
 * @implements ProcessorInterface<PlannedTask, PlannedTask>
 */
final class PlannedTaskPatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlannedTask
    {
        $id = $uriVariables['id'];
        $task = $this->tasks->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $task) {
            throw new NotFoundHttpException('No planned task at this IRI.');
        }

        $status = PlannedMaintenanceStatus::tryFrom($data->status);
        if (null === $status) {
            throw new UnprocessableEntityHttpException(sprintf('Unknown status "%s"; expected open, done or dismissed.', $data->status));
        }

        if (PlannedMaintenanceStatus::Open === $status) {
            $task->reopen();
        } else {
            $task->close($status, new \DateTimeImmutable());
        }

        $this->em->flush();

        return PlannedTask::fromEntity($task);
    }
}
