<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/vehicles/{vehicleId}/service-schedule/{id} — remove a recurring rule from the card.
 * A soft tombstone (recoverable: re-POSTing the same key un-deletes it), matching the rest of the
 * garage's soft-delete discipline. Owner-gated; fans the fresh vehicle out over Mercure.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ServiceScheduleDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private DocumentManager $em,
        private OwnerContext $owner,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $vehicleId = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->find($vehicleId instanceof Ulid ? $vehicleId : Ulid::fromString((string) $vehicleId));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $rule = $vehicle->serviceIntervalById((string) $uriVariables['id']);
        if (null === $rule) {
            throw new NotFoundHttpException('No such schedule rule on this vehicle.');
        }

        $rule->delete();
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return null;
    }
}
