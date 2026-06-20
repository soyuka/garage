<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Dto\MaintenanceRecordInput;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{vehicleId}/maintenance-records — log a past service by hand. Resolves the
 * vehicle by the URI ULID through the shared recorder (owner-gated, so a foreign @id is a 404),
 * appends an immutable record (source "user"), and — like log_maintenance — closes any open task of
 * the same type and promotes the reading onto the vehicle. Fans the vehicle out over Mercure.
 *
 * @implements ProcessorInterface<MaintenanceRecordInput, GarageMaintenanceRecord>
 */
final class MaintenanceRecordCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly MaintenanceRecorder $recorder,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageMaintenanceRecord
    {
        $id = $uriVariables['vehicleId'];
        $vehicleId = $id instanceof Ulid ? $id : Ulid::fromString((string) $id);

        try {
            $performedAt = new \DateTimeImmutable($data->performedAt);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid performedAt date "%s"; expected YYYY-MM-DD.', $data->performedAt));
        }

        $unit = null;
        if (null !== $data->usageUnit) {
            // The dashboard speaks the garage spelling of engine hours ('h'); the log enum spells it
            // 'hours' — normalise before mapping so a card reading lands on the right case.
            $unit = UsageUnit::tryFrom('h' === $data->usageUnit ? 'hours' : $data->usageUnit);
            if (null === $unit) {
                throw new UnprocessableEntityHttpException(sprintf('Unknown usageUnit "%s"; expected km, mi or h.', $data->usageUnit));
            }
        }

        $outcome = $this->recorder->log(
            vehicleId: $vehicleId,
            make: null,
            model: null,
            year: null,
            // Placeholder: the vehicle resolves by @id, so its type is read from the row, not this.
            vehicleType: VehicleType::Car,
            carId: null,
            plate: null,
            vin: null,
            confirmNew: false,
            type: $data->type,
            performedAt: $performedAt,
            source: 'user',
            label: $data->label,
            usageValue: $data->usageValue,
            usageUnit: $unit,
            notes: $data->notes,
            costCents: null !== $data->cost ? (int) round($data->cost * 100) : null,
            currency: null !== $data->cost ? ($data->currency ?? 'EUR') : $data->currency,
            performedBy: $data->performedBy,
            partsUsed: $data->partsUsed,
        );

        if ('not_found' === $outcome->status) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $record = $outcome->record;
        $this->publisher->publishVehicle($record->getVehicle());

        return GarageMaintenanceRecord::fromEntity($record);
    }
}
