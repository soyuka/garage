<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlannedTaskPart;
use App\Mercure\DomainPublisher;
use App\Repository\PlannedMaintenanceRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a single shopping-list line. POST (no {id}) appends a part — upsert by ref, mirroring
 * mergeParts. PATCH (with {id}) merge-edits the line the provider loaded. Both flush, fan the owning
 * vehicle out over Mercure like every write, and return the resulting line.
 *
 * @implements ProcessorInterface<PlannedTaskPart, PlannedTaskPart>
 */
final class PlannedTaskPartProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PlannedMaintenanceRepository $tasks,
        private readonly DocumentManager $em,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlannedTaskPart
    {
        $taskId = $uriVariables['taskId'];
        $task = $this->tasks->find($taskId instanceof Ulid ? $taskId : Ulid::fromString((string) $taskId));
        if (null === $task) {
            throw new NotFoundHttpException('No planned task at this IRI.');
        }

        $fields = [
            'ref' => $data->ref,
            'label' => $data->label,
            'brand' => $data->brand,
            'qty' => $data->qty,
            'priceCents' => $data->priceCents,
        ];

        if (isset($uriVariables['id'])) {
            $id = $uriVariables['id'];
            $part = $task->findPart($id instanceof Ulid ? $id : Ulid::fromString((string) $id));
            if (null === $part) {
                throw new NotFoundHttpException('No part at this IRI.');
            }
            $part->update($fields);
        } else {
            $part = $task->addPart($fields);
        }

        $this->em->flush();
        $this->publisher->publishVehicle($task->getVehicle());

        return PlannedTaskPart::fromArray($part->toArray(), $task->getId());
    }
}
