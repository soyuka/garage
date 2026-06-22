<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\ServiceInterval;
use App\Garage\SetScheduleOutcome;
use App\Garage\VehicleType;
use App\Garage\VehicleWriteResolver;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * Writes the per-vehicle recurring SCHEDULE — the layer that lets the agent persist a full
 * manufacturer schedule (e.g. the TORO 246-H answer: oil every 50 h, paper air cartridge every
 * 100 h, spark plug inspect@25 h vs replace@100 h) instead of collapsing it to one log event.
 *
 * Resolves the vehicle through the same {@see VehicleWriteResolver} as the record/plan paths, then
 * UPSERTS each rule keyed by {@see ServiceInterval::key()}: re-sending a cadence refreshes the one
 * rule in place, new cadences append, unmentioned ones stay (additive, like the fact store).
 */
final class ServiceSchedulePlanner
{
    public function __construct(
        private readonly VehicleWriteResolver $writeResolver,
        private readonly DocumentManager $entityManager,
    ) {
    }

    /**
     * @param list<ServiceInterval> $intervals the recurring rules to upsert onto the vehicle
     */
    public function setSchedule(
        ?Ulid $vehicleId,
        ?string $make,
        ?string $model,
        ?int $year,
        VehicleType $vehicleType,
        ?int $carId,
        ?string $plate,
        ?string $vin,
        bool $confirmNew,
        string $source,
        array $intervals,
    ): SetScheduleOutcome {
        $resolution = $this->writeResolver->resolveForWrite(
            $vehicleId, $make, $model, $year, $vehicleType, $carId, $plate, $vin, $confirmNew, $source,
        );

        if ('ambiguous' === $resolution->status) {
            return SetScheduleOutcome::ambiguous($resolution->candidates);
        }
        if ('not_found' === $resolution->status) {
            return SetScheduleOutcome::notFound($resolution->vehicleId);
        }

        $vehicle = $resolution->vehicle;
        foreach ($intervals as $interval) {
            $vehicle->setServiceInterval($interval);
        }

        $this->entityManager->flush();

        return SetScheduleOutcome::set($vehicle, $vehicle->serviceSchedule());
    }
}
