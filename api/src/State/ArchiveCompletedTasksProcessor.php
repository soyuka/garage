<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ArchiveTasksOutput;
use App\Maintenance\MaintenanceRecorder;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{vehicleId}/planned-tasks/archive — the dashboard's "Archiver les entretiens
 * terminés". Transforms every done-but-unfiled task on the vehicle into a past-maintenance record
 * (stamped now with the vehicle's current reading) and files the task off the current checklist.
 * Owner-gated like every garage write; idempotent (a re-run archives nothing). Publishes the vehicle
 * over Mercure so the checklist and history live-update in every tab.
 *
 * @implements ProcessorInterface<mixed, ArchiveTasksOutput>
 */
final readonly class ArchiveCompletedTasksProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private MaintenanceRecorder $recorder,
        private OwnerContext $owner,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ArchiveTasksOutput
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $records = $this->recorder->archiveCompletedTasks($vehicle, new \DateTimeImmutable());

        if ([] !== $records) {
            $this->publisher->publishVehicle($vehicle);
        }

        // The done tasks are filed as ONE visit carrying an item per task, so "archived" counts the
        // line-items (= tasks moved off the checklist), not the single record row created.
        $archived = array_sum(array_map(static fn ($record): int => \count($record->getItems()), $records));

        return new ArchiveTasksOutput(
            archived: $archived,
            records: array_map(static fn ($record): string => '/garage/maintenance-records/'.$record->getId(), $records),
        );
    }
}
