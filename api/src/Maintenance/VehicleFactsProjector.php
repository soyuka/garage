<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\Vehicle;
use App\Entity\VehicleFact;

/**
 * Projects a vehicle's facts as a lens over its maintenance items: the item list is the single
 * store, facts have no store of their own. Every item's `attributes` are merged into one key→fact
 * map. On a key collision across items a verified attribute (catalog/SIV) wins over an unverified
 * one (agent web/llm/user) regardless of age — the precedence rule that stops a hallucinated spec
 * shadowing an authoritative one; among equally-verified attributes the most recent wins.
 */
final class VehicleFactsProjector
{
    /**
     * @return list<VehicleFact>
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        /** @var array<string, VehicleFact> $byKey */
        $byKey = [];
        foreach ($vehicle->getPlannedTasks() as $task) {
            foreach ($task->getAttributes() as $fact) {
                // A tombstoned fact (removed from the card) is off the projection — and it does NOT
                // shadow a live attribute for the same key on another item, so it cannot resurrect a
                // value the user removed.
                if ($fact->isDeleted()) {
                    continue;
                }
                $incumbent = $byKey[$fact->getKey()] ?? null;
                if (null === $incumbent || $this->supersedes($fact, $incumbent)) {
                    $byKey[$fact->getKey()] = $fact;
                }
            }
        }

        return array_values($byKey);
    }

    private function supersedes(VehicleFact $candidate, VehicleFact $incumbent): bool
    {
        if ($candidate->isVerified() !== $incumbent->isVerified()) {
            return $candidate->isVerified();
        }

        return $candidate->getAddedAt() >= $incumbent->getAddedAt();
    }
}
