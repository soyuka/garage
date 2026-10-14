<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\VehicleFactInput;
use App\Entity\VehicleFact;
use App\Documentation\OwnedVehicleLocator;
use App\Garage\GarageVehicleView;
use App\Maintenance\VehicleFactWriter;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{id}/facts — the dashboard's manual fact write. Records one user-supplied
 * spec as a {@see VehicleFact} attribute (source "user", unverified) on the vehicle's single
 * USER_FACTS spec-carrier item, the user-side counterpart of the catalog spec sheet, through the
 * shared {@see VehicleFactWriter} (upserts by key, publishes over Mercure). Returns the fresh vehicle
 * read-model with facts inlined.
 *
 * @implements ProcessorInterface<VehicleFactInput, GarageVehicle>
 */
final class GarageVehicleFactProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OwnedVehicleLocator $vehicles,
        private readonly GarageVehicleView $view,
        private readonly VehicleFactWriter $writer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->require($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        $this->writer->set($vehicle, [VehicleFact::fromSource(
            key: $data->key,
            value: $data->value,
            source: 'user',
            unit: $data->unit,
        )]);

        $resource = GarageVehicle::fromEntity($vehicle);
        $resource->facts = $this->view->factsOf($vehicle);
        $resource->factCount = \count($resource->facts);

        return $resource;
    }
}
