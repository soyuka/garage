<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\VehicleCreateInput;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Mercure\DomainPublisher;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /garage/vehicles — the dashboard's "add a car". Creates a blank, owner-scoped vehicle the user
 * fills in via the inline-editable card; identity defaults to a placeholder, never the agent's dedup
 * path (a human adding their own car is not a duplicate to reconcile). Fans the 'created' event out
 * over Mercure so the parc list grows live, and returns the fresh read-model.
 *
 * @implements ProcessorInterface<VehicleCreateInput, GarageVehicle>
 */
final class GarageVehicleCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DocumentManager $em,
        private readonly OwnerContext $owner,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle
    {
        $type = null !== $data->vehicleType ? VehicleType::tryFrom($data->vehicleType) : VehicleType::Car;
        if (null === $type) {
            throw new UnprocessableEntityHttpException(sprintf('Unknown vehicleType "%s".', $data->vehicleType));
        }

        $vehicle = new Vehicle(
            make: $data->make ?? 'Nouveau véhicule',
            model: $data->model ?? '',
            vehicleType: $type,
            source: 'user',
        );
        $vehicle->setOwner($this->owner->require());

        $this->em->persist($vehicle);
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle, 'created');

        return GarageVehicle::fromEntity($vehicle);
    }
}
