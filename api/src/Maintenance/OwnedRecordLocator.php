<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceRecord;
use App\Repository\MaintenanceRecordRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve a maintenance record by ULID, owner-gated through its vehicle — the same gate the
 * record's own PATCH/DELETE enforce (a foreign or soft-deleted vehicle's record is a 404). Shared
 * by the three attachment controllers so the tenancy check lives in one place.
 */
final readonly class OwnedRecordLocator
{
    public function __construct(
        private MaintenanceRecordRepository $records,
    ) {
    }

    public function require(string $id): MaintenanceRecord
    {
        $record = $this->records->findOwned($id);

        if (null === $record) {
            throw new NotFoundHttpException('No maintenance record at this IRI.');
        }

        return $record;
    }
}
