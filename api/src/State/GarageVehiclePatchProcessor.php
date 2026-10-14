<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Garage\UsageUnit;
use App\Garage\VehicleType;
use App\Documentation\OwnedVehicleLocator;
use App\Mercure\DomainPublisher;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * The dashboard editable-card write: applies a JSON Merge Patch of a vehicle's own scalars (usage
 * reading, plate) straight to the resolved row — no dedup, since the vehicle is already identified
 * by its IRI. Identity fields are read-only at the resource layer, so only usage/plate ever reach
 * here. After the flush it publishes the fresh vehicle over Mercure so the dashboard live-updates.
 *
 * @implements ProcessorInterface<GarageVehicle, GarageVehicle>
 */
final class GarageVehiclePatchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OwnedVehicleLocator $vehicles,
        private readonly DocumentManager $em,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->require($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

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

        $vehicleType = VehicleType::tryFrom($data->vehicleType);
        if (null === $vehicleType) {
            throw new UnprocessableEntityHttpException(sprintf('Unknown vehicleType "%s".', $data->vehicleType));
        }

        // merge-patch+json re-hydrates unsent fields from the current resource (the provider mapped
        // the entity first), so every scalar here is either the edited value or the unchanged current
        // one — applying them all is safe and never clobbers an omitted field.
        $vehicle->setMake($data->make);
        $vehicle->setModel($data->model);
        $vehicle->setYear($data->year);
        $vehicle->setEngineVariant($data->engineVariant);
        $vehicle->setEngineCode($data->engineCode);
        $vehicle->setKType($data->kType);
        $vehicle->setVehicleType($vehicleType);
        $vehicle->setPlate($data->plate);

        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return GarageVehicle::fromEntity($vehicle);
    }
}
