<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Repository\MaintenanceRecordRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /garage/maintenance-records/{id} by ULID. Returns null (→ 404) on no match.
 *
 * @implements ProviderInterface<GarageMaintenanceRecord>
 */
final class GarageMaintenanceRecordProvider implements ProviderInterface
{
    public function __construct(private readonly MaintenanceRecordRepository $records)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?GarageMaintenanceRecord
    {
        $id = $uriVariables['id'];
        $record = $this->records->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        return null === $record ? null : GarageMaintenanceRecord::fromEntity($record);
    }
}
