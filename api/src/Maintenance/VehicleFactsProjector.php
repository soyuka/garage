<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;

/**
 * Projects a vehicle's facts as a lens over two stores it owns, never a store of its own: the
 * planned-maintenance `attributes` (typed specs) AND the parts actually fitted on its logged
 * maintenance visits (a real installed part is evidence of the vehicle's spec). Both feed one
 * key→fact map.
 *
 * Precedence on a key collision, highest first:
 *   1. a manual dashboard edit ({@see USER_SOURCE}) — the owner's deliberate correction of their own
 *      card is ground truth and wins everything (only the dashboard writes this source);
 *   2. a logged bill part ({@see LOG_SOURCE}) — you physically fitted it, so it outranks a spec;
 *   3. a verified attribute (catalog/SIV) — stops a hallucinated spec shadowing an authoritative one;
 *   4. an unverified attribute (agent web/llm).
 * Within one tier the most recent wins (for parts that is the latest visit's performedAt).
 */
final class VehicleFactsProjector
{
    /**
     * Source of a fact entered by the owner from the dashboard fact editor — a deliberate manual
     * correction. The agent never writes this source (it uses partsapi/web/llm), so it uniquely marks
     * an owner override and the precedence ranks it on top.
     */
    public const string USER_SOURCE = 'user';

    /**
     * Source marker for a fact derived from a part fitted on a logged maintenance visit (as opposed
     * to a typed spec). Distinct from the catalog/agent sources so the precedence ranks it on top.
     */
    public const string LOG_SOURCE = 'log';

    // A logged item whose type is the free-text catch-all carries no canonical fact key (its parts
    // would collapse under one "other_ref"), so it is not projected as a ref fact.
    private const string UNKEYED_TYPE = 'other';

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
                $this->offer($byKey, $fact);
            }
        }

        foreach ($vehicle->getMaintenanceRecords() as $record) {
            foreach ($this->partFacts($record) as $fact) {
                // A projected fact has no document to tombstone; the user removes it via a dated
                // per-vehicle suppression. Honour it while the source visit is no newer than the
                // suppression — a later fitment (newer performedAt) is fresh evidence and re-surfaces.
                $suppressedAt = $vehicle->factSuppressedAt($fact->getKey());
                if (null !== $suppressedAt && $fact->getAddedAt() <= $suppressedAt) {
                    continue;
                }
                $this->offer($byKey, $fact);
            }
        }

        return array_values($byKey);
    }

    /**
     * @param array<string, VehicleFact> $byKey
     */
    private function offer(array &$byKey, VehicleFact $fact): void
    {
        $incumbent = $byKey[$fact->getKey()] ?? null;
        if (null === $incumbent || $this->supersedes($fact, $incumbent)) {
            $byKey[$fact->getKey()] = $fact;
        }
    }

    /**
     * Derive a ref fact per part fitted on the visit: key `{type}_ref`, value the brand-prefixed part
     * number ("MANN-FILTER W712/73"), stamped at the visit date so the latest fitment wins.
     *
     * @return list<VehicleFact>
     */
    private function partFacts(MaintenanceRecord $record): array
    {
        $facts = [];
        foreach ($record->getItems() as $item) {
            $type = $item['type'];
            if (self::UNKEYED_TYPE === $type) {
                continue;
            }
            foreach ($item['parts'] ?? [] as $part) {
                $ref = trim((string) ($part['ref'] ?? ''));
                if ('' === $ref) {
                    continue;
                }
                $brand = trim((string) ($part['brand'] ?? ''));
                $facts[] = VehicleFact::fromSource(
                    key: $type.'_ref',
                    value: '' !== $brand ? $brand.' '.$ref : $ref,
                    source: self::LOG_SOURCE,
                    addedAt: $record->getPerformedAt(),
                );
            }
        }

        return $facts;
    }

    private function supersedes(VehicleFact $candidate, VehicleFact $incumbent): bool
    {
        $candidateRank = $this->rank($candidate);
        $incumbentRank = $this->rank($incumbent);
        if ($candidateRank !== $incumbentRank) {
            return $candidateRank > $incumbentRank;
        }

        return $candidate->getAddedAt() >= $incumbent->getAddedAt();
    }

    private function rank(VehicleFact $fact): int
    {
        return match (true) {
            self::USER_SOURCE === $fact->getSource() => 3,
            self::LOG_SOURCE === $fact->getSource() => 2,
            $fact->isVerified() => 1,
            default => 0,
        };
    }
}
