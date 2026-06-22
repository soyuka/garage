<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Maintenance\VehicleFactsProjector;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/vehicles/{id}/facts/{key} — remove a fact from the card. Facts have no store of
 * their own: a fact is either an attribute on one of the vehicle's maintenance items OR a value
 * PROJECTED from a part fitted on a logged visit. This removes both contributions for the key:
 *  - it tombstones EVERY attribute matching the key across the items (the projection then drops it,
 *    even a verified catalog value), recoverable by re-POSTing the key;
 *  - for a history-derived (projected) fact, which has no document to tombstone, it records a dated
 *    per-vehicle suppression so the projection hides it (a later fitment re-surfaces it).
 * Fans the fresh vehicle out over Mercure like any write.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final class GarageVehicleFactDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $em,
        private readonly OwnerContext $owner,
        private readonly DomainPublisher $publisher,
        private readonly VehicleFactsProjector $projector,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $key = (string) $uriVariables['key'];
        $removed = false;
        foreach ($vehicle->getPlannedTasks() as $task) {
            $fact = $task->attributeByKey($key);
            if (null !== $fact && !$fact->isDeleted()) {
                $fact->delete();
                $removed = true;
            }
        }

        // A live history-derived fact (projected from a logged part) carries no attribute to
        // tombstone; suppress the key instead so the projection drops it.
        foreach ($this->projector->forVehicle($vehicle) as $fact) {
            if ($fact->getKey() === $key && VehicleFactsProjector::LOG_SOURCE === $fact->getSource()) {
                $vehicle->suppressFactKey($key);
                $removed = true;
                break;
            }
        }

        if (!$removed) {
            throw new NotFoundHttpException(sprintf('No fact "%s" on this vehicle.', $key));
        }

        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return null;
    }
}
