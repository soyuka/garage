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
 * DELETE /garage/vehicles/{id} — the dashboard's "remove a car". A soft delete: it tombstones the row
 * (its maintenance history is never erased) so it drops off every read path, and fans a 'deleted'
 * event out over Mercure so other tabs drop the card too. Owner-gated like every garage write.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final class GarageVehicleDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $em,
        private readonly OwnerContext $owner,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $vehicle->delete();
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle, 'deleted');

        return null;
    }
}
