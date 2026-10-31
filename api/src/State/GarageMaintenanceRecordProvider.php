<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GarageMaintenanceRecord;
use App\Maintenance\OwnedRecordLocator;

/**
 * Dereferences /garage/maintenance-records/{id} by ULID. 404s (NotFoundHttpException) on no match or a foreign owner.
 *
 * @implements ProviderInterface<GarageMaintenanceRecord>
 */
final class GarageMaintenanceRecordProvider implements ProviderInterface
{
    public function __construct(private readonly OwnedRecordLocator $records)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GarageMaintenanceRecord
    {
        $id = $uriVariables['id'];

        return GarageMaintenanceRecord::fromEntity($this->records->require((string) $id));
    }
}
