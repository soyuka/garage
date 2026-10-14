<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The single write path for a vehicle's facts, shared by the dashboard HTTP endpoints and the MCP
 * `set_vehicle_facts` / `remove_vehicle_facts` tools. A vehicle has no fact store of its own: a
 * written fact lands as an attribute on the vehicle's one USER_FACTS spec-carrier item (off the
 * checklist), keyed so one key holds one value there. Replacing a key also tombstones the unverified
 * copies of it on other items, so a superseded value cannot resurface once the new one is removed.
 */
final class VehicleFactWriter
{
    public const string CARRIER_TYPE = 'user_facts';

    // The sources a caller may claim. Catalog/SIV (authoritative) and log (bill-derived) are
    // reserved: only the importers write them, which is what keeps `verified` trustworthy.
    public const array WRITABLE_SOURCES = ['user', 'web', 'llm', 'motul'];

    public function __construct(
        private readonly DocumentManager $em,
        private readonly VehicleRepository $vehicles,
        private readonly OwnerContext $owner,
        private readonly VehicleFactsProjector $projector,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function vehicleOf(Ulid $id): ?Vehicle
    {
        $vehicle = $this->vehicles->find($id);

        return null === $vehicle || $vehicle->getOwner() !== $this->owner->current() || $vehicle->isDeleted() ? null : $vehicle;
    }

    /**
     * @param list<VehicleFact> $facts later entries win over earlier ones with the same key
     */
    public function set(Vehicle $vehicle, array $facts): void
    {
        $carrier = $this->carrier($vehicle);
        foreach ($facts as $fact) {
            foreach ($vehicle->getPlannedTasks() as $task) {
                $existing = $task === $carrier ? null : $task->attributeByKey($fact->getKey());
                if (null !== $existing && !$existing->isDeleted() && !$existing->isVerified()) {
                    $existing->delete();
                }
            }
            $carrier->setAttribute($fact);
        }

        $this->em->persist($carrier);
        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);
    }

    /**
     * Soft-delete every contribution to each key: tombstone all matching attributes (even a verified
     * catalog value, recoverable by writing the key again) and suppress a bill-derived fact, which
     * has no document to tombstone, until a newer fitment re-surfaces it.
     *
     * @param list<string> $keys
     *
     * @return array{removed: list<string>, notFound: list<string>}
     */
    public function remove(Vehicle $vehicle, array $keys): array
    {
        $removed = [];
        $notFound = [];
        foreach ($keys as $key) {
            $hit = false;
            foreach ($vehicle->getPlannedTasks() as $task) {
                $fact = $task->attributeByKey($key);
                if (null !== $fact && !$fact->isDeleted()) {
                    $fact->delete();
                    $hit = true;
                }
            }

            foreach ($this->projector->forVehicle($vehicle) as $fact) {
                if ($fact->getKey() === $key && VehicleFactsProjector::LOG_SOURCE === $fact->getSource()) {
                    $vehicle->suppressFactKey($key);
                    $hit = true;
                    break;
                }
            }

            $hit ? $removed[] = $key : $notFound[] = $key;
        }

        if ([] !== $removed) {
            $this->em->flush();
            $this->publisher->publishVehicle($vehicle);
        }

        return ['removed' => $removed, 'notFound' => $notFound];
    }

    /**
     * Keys whose written fact does not win the projection (a verified catalog value, a bill part or
     * an owner edit outranks it) — the caller should not present those as the vehicle's value.
     *
     * @param list<VehicleFact> $written
     *
     * @return list<string>
     */
    public function shadowed(Vehicle $vehicle, array $written): array
    {
        $effective = [];
        foreach ($this->projector->forVehicle($vehicle) as $fact) {
            $effective[$fact->getKey()] = $fact;
        }

        $shadowed = [];
        foreach ($written as $fact) {
            if (($effective[$fact->getKey()] ?? null) !== $fact) {
                $shadowed[] = $fact->getKey();
            }
        }

        return array_values(array_unique($shadowed));
    }

    private function carrier(Vehicle $vehicle): PlannedMaintenance
    {
        foreach ($vehicle->getPlannedTasks() as $task) {
            if (self::CARRIER_TYPE === $task->getType() && $task->isOpen()) {
                return $task;
            }
        }

        return new PlannedMaintenance($vehicle, self::CARRIER_TYPE, 'user', owner: $vehicle->getOwner());
    }
}
