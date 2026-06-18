<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\VehicleFactInput;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Garage\GarageVehicleView;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{id}/facts — the dashboard's manual fact write. Records one user-supplied
 * spec as a {@see VehicleFact} attribute (source "user", unverified) on the vehicle's single
 * USER_FACTS spec-carrier item, the user-side counterpart of the catalog spec sheet. Upserts by key
 * so re-entering a value replaces it in place. The vehicle has no fact store of its own — facts are
 * attributes on maintenance items — so this is how a manual answer reaches the projection. Returns
 * the fresh vehicle read-model (facts inlined) and fans the write out over Mercure like any other.
 *
 * @implements ProcessorInterface<VehicleFactInput, GarageVehicle>
 */
final class GarageVehicleFactProcessor implements ProcessorInterface
{
    // The KNOWN item user-stated specs hang on — a spec carrier (attributes only, no parts/target/
    // date), off the checklist, mirroring GarageResolver::CATALOG_SPECS_TYPE for the catalog side.
    public const string USER_FACTS_TYPE = 'user_facts';

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentManager $em,
        private readonly GarageVehicleView $view,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageVehicle
    {
        $id = $uriVariables['id'];
        $vehicle = $this->vehicles->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $item = $this->userFactsItem($vehicle);
        $item->setAttribute(VehicleFact::fromSource(
            key: $data->key,
            value: $data->value,
            source: 'user',
            unit: $data->unit,
        ));
        $this->em->persist($item);
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        $resource = GarageVehicle::fromEntity($vehicle);
        $resource->facts = $this->view->factsOf($vehicle);
        $resource->factCount = \count($resource->facts);

        return $resource;
    }

    /**
     * The vehicle's open user-facts carrier, created on first use. One per vehicle keeps every
     * manual spec under a single keyed map (one attribute per key).
     */
    private function userFactsItem(Vehicle $vehicle): PlannedMaintenance
    {
        foreach ($vehicle->getPlannedTasks() as $task) {
            if (self::USER_FACTS_TYPE === $task->getType() && $task->isOpen()) {
                return $task;
            }
        }

        return new PlannedMaintenance($vehicle, self::USER_FACTS_TYPE, 'user');
    }
}
