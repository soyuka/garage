<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mercure\DomainPublisher;
use App\Repository\MaintenanceRecordRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/maintenance-records/{id} — remove a logged visit by hand. Owner-gated through the
 * record's vehicle (a foreign or soft-deleted vehicle's record is a 404). A hard delete: history is
 * a manual log here, not an audit trail. Fans the vehicle out over Mercure so the rail refreshes.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MaintenanceRecordDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private MaintenanceRecordRepository $records,
        private DocumentManager $em,
        private OwnerContext $owner,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        $record = $this->records->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $record || $record->getVehicle()->getOwner() !== $this->owner->current() || $record->getVehicle()->isDeleted()) {
            throw new NotFoundHttpException('No maintenance record at this IRI.');
        }

        $vehicle = $record->getVehicle();
        $this->em->remove($record);
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return null;
    }
}
