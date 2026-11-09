<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

final readonly class OwnedVehicleLocator
{
    public function __construct(
        private VehicleRepository $vehicles,
    ) {
    }

    public function find(string|Ulid $id): ?Vehicle
    {
        return $this->vehicles->findOwned($id);
    }

    public function require(string|Ulid $id): Vehicle
    {
        return $this->find($id) ?? throw new NotFoundHttpException('No vehicle at this IRI.');
    }
}
