<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class OwnedVehicleLocator
{
    public function __construct(
        private VehicleRepository $vehicles,
        private OwnerContext $owner,
    ) {
    }

    public function require(string $id): Vehicle
    {
        $vehicle = $this->vehicles->find($id);

        if (null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted()) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        return $vehicle;
    }
}
