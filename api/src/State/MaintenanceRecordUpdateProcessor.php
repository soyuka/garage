<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Dto\MaintenanceRecordInput;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
use App\Repository\MaintenanceRecordRepository;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * PATCH /garage/maintenance-records/{id} — edit a logged visit by hand. Owner-gated through the
 * record's vehicle (a foreign or soft-deleted vehicle's record is a 404). Replaces the whole visit
 * from the body: the dashboard editor holds the full state, so every field present is authoritative.
 * performedAt is kept when omitted (blank); items are kept when the body carries none — a visit can
 * never be emptied to zero line-items. Fans the vehicle out over Mercure like any write.
 *
 * @implements ProcessorInterface<MaintenanceRecordInput, GarageMaintenanceRecord>
 */
final class MaintenanceRecordUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly MaintenanceRecordRepository $records,
        private readonly DocumentManager $em,
        private readonly OwnerContext $owner,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GarageMaintenanceRecord
    {
        $id = $uriVariables['id'];
        $record = $this->records->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $record || $record->getVehicle()->getOwner() !== $this->owner->current() || $record->getVehicle()->isDeleted()) {
            throw new NotFoundHttpException('No maintenance record at this IRI.');
        }

        $items = $data->resolveItems();
        if ([] !== $items) {
            $record->replaceItems($items);
        }

        if ('' !== trim($data->performedAt)) {
            try {
                $record->setPerformedAt(new \DateTimeImmutable($data->performedAt));
            } catch (\Exception) {
                throw new UnprocessableEntityHttpException(sprintf('Invalid performedAt date "%s"; expected YYYY-MM-DD.', $data->performedAt));
            }
        }

        if (null !== $data->usageUnit) {
            // Dashboard speaks 'h'; the log enum spells engine hours 'hours'.
            $unit = UsageUnit::tryFrom('h' === $data->usageUnit ? 'hours' : $data->usageUnit);
            if (null === $unit) {
                throw new UnprocessableEntityHttpException(sprintf('Unknown usageUnit "%s"; expected km, mi or h.', $data->usageUnit));
            }
            $record->setUsageUnit($unit);
        } else {
            $record->setUsageUnit(null);
        }

        $record->setLabel($data->label);
        $record->setUsageValue($data->usageValue);
        $record->setNotes($data->notes);
        $record->setPerformedBy($data->performedBy);
        $record->setCostCents(null !== $data->cost ? (int) round($data->cost * 100) : null);
        $record->setCurrency(null !== $data->cost ? ($data->currency ?? 'EUR') : $data->currency);

        $this->em->flush();
        $this->publisher->publishVehicle($record->getVehicle());

        return GarageMaintenanceRecord::fromEntity($record);
    }
}
