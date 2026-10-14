<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

final readonly class OwnedVehicleLocator
{
    public function __construct(
        private VehicleRepository $vehicles,
        private OwnerContext $owner,
    ) {
    }

    public function find(string|Ulid $id): ?Vehicle
    {
        $vehicle = $this->vehicles->find($id);

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            return null;
        }

        return $vehicle;
    }

    public function require(string|Ulid $id): Vehicle
    {
        return $this->find($id) ?? throw new NotFoundHttpException('No vehicle at this IRI.');
    }
}
