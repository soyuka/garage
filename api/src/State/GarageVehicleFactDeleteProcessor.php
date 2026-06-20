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
 * DELETE /garage/vehicles/{id}/facts/{key} — remove a fact from the card. Facts have no store of
 * their own (they are attributes on the vehicle's maintenance items), so this tombstones EVERY
 * attribute matching the key across the vehicle's items: the projection then drops it, even a
 * verified catalog value. Recoverable — re-POSTing the key lands a fresh, live attribute. Fans the
 * fresh vehicle out over Mercure like any write.
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

        if (!$removed) {
            throw new NotFoundHttpException(sprintf('No fact "%s" on this vehicle.', $key));
        }

        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return null;
    }
}
