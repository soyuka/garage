<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Result of archiving a vehicle's completed checklist: how many done tasks were filed into the
 * past-maintenance log, and the IRIs of the records just created. `archived` is 0 on a re-run
 * (idempotent) — the dashboard reads it to toast "N entretiens archivés" and refetch both lists.
 */
final class ArchiveTasksOutput
{
    /**
     * @param list<string> $records IRIs of the maintenance records minted from the done tasks
     */
    public function __construct(
        #[ApiProperty(description: 'Number of done tasks transformed into past-maintenance records.')]
        public int $archived = 0,
        #[ApiProperty(description: 'IRIs of the maintenance records just created.')]
        public array $records = [],
    ) {
    }
}
