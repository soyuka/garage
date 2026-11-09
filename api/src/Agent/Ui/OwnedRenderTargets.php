<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Documentation\OwnedVehicleLocator;
use App\Garage\ToolError;
use App\Garage\ToolResult;
use App\Maintenance\OwnedPlannedTaskLocator;
use Symfony\Component\Uid\Ulid;

final readonly class OwnedRenderTargets implements RenderTargets
{
    public const string VEHICLES = '/garage/vehicles/';
    public const string TASKS = '/garage/planned-tasks/';

    public function __construct(
        private OwnedVehicleLocator $vehicles,
        private OwnedPlannedTaskLocator $tasks,
    ) {
    }

    public function vehicle(string $handle): string|ToolError
    {
        $ulid = self::ulid($handle, self::VEHICLES);
        if (null === $ulid) {
            return ToolResult::error('invalid_input', 'Not a vehicle @id: pass an @id from list_vehicles, e.g. /garage/vehicles/01J….');
        }
        if (null === $this->vehicles->find($ulid)) {
            return ToolResult::error('not_found', 'No such vehicle in this garage. Use an @id from list_vehicles.');
        }

        return self::VEHICLES.$ulid;
    }

    public function task(string $handle): string|ToolError
    {
        $ulid = self::ulid($handle, self::TASKS);
        if (null === $ulid) {
            return ToolResult::error('invalid_input', 'Not a task id: pass an open task id from the turn context (a ULID or /garage/planned-tasks/<ULID>).');
        }
        if (null === $this->tasks->find(Ulid::fromString($ulid))) {
            return ToolResult::error('not_found', 'No such open task in this garage. Use a task id from the turn context.');
        }

        return self::TASKS.$ulid;
    }

    /** The ULID of a bare ULID or a `<prefix><ULID>` IRI; anything else (absolute URL, other collection, path tricks) is null. */
    public static function ulid(string $handle, string $prefix): ?string
    {
        return 1 === preg_match('#^(?:'.preg_quote($prefix, '#').')?([0-7][0-9A-HJKMNP-TV-Z]{25})$#D', $handle, $m) ? $m[1] : null;
    }
}
