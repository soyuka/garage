<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Garage\UsageUnit;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * The dashboard editable-card write: applies a JSON Merge Patch of a vehicle's own scalars (usage
 * reading, plate) straight to the resolved row — no dedup, since the vehicle is already identified
 * by its IRI. Identity fields are read-only at the resource layer, so only usage/plate ever reach
 * here. The flush triggers DomainEventPublisher, so the change fans out over Mercure like any write.
 *
 * @implements ProcessorInterface<GarageVehicle, GarageVehicle>
 */
final class GarageVehiclePatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        // A usage reading is meaningless without its unit (km/mi/h) — and vice versa.
        if ((null === $data->usageValue) !== (null === $data->usageUnit)) {
            throw new UnprocessableEntityHttpException('usageValue and usageUnit must be set together.');
        }

        if (null !== $data->usageValue && null !== $data->usageUnit) {
            $unit = UsageUnit::tryFrom($data->usageUnit);
            if (null === $unit) {
                throw new UnprocessableEntityHttpException(sprintf('Unknown usageUnit "%s"; expected km, mi or h.', $data->usageUnit));
            }
            $vehicle->setUsage($data->usageValue, $unit);
        }

        $vehicle->setPlate($data->plate);

        $this->em->flush();

        return GarageVehicle::fromEntity($vehicle);
    }
}
