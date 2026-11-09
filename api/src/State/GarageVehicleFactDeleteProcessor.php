<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Maintenance\VehicleFactWriter;
use App\Repository\VehicleRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/vehicles/{id}/facts/{key} — remove a fact from the card, through the shared
 * {@see VehicleFactWriter} (soft-delete of every contribution to the key; 404 when there is none).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class GarageVehicleFactDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleFactWriter $writer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->findOwned($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $key = (string) $uriVariables['key'];
        if ([] === $this->writer->remove($vehicle, [$key])['removed']) {
            throw new NotFoundHttpException(\sprintf('No fact "%s" on this vehicle.', $key));
        }

        return null;
    }
}
