<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Agent\Ui\OwnedRenderTargets;
use App\Agent\Ui\RenderTargets;
use App\Garage\ToolError;
use App\Garage\ToolResult;

/**
 * {@see OwnedRenderTargets} without a database: the same handle grammar, ownership given as a list of
 * owned ULIDs.
 */
final readonly class TestRenderTargets implements RenderTargets
{
    /**
     * @param list<string> $ownedVehicles
     * @param list<string> $ownedTasks
     */
    public function __construct(private array $ownedVehicles = [], private array $ownedTasks = [])
    {
    }

    public function vehicle(string $handle): string|ToolError
    {
        return self::resolve($handle, OwnedRenderTargets::VEHICLES, $this->ownedVehicles);
    }

    public function task(string $handle): string|ToolError
    {
        return self::resolve($handle, OwnedRenderTargets::TASKS, $this->ownedTasks);
    }

    /**
     * @param list<string> $owned
     */
    private static function resolve(string $handle, string $prefix, array $owned): string|ToolError
    {
        $ulid = OwnedRenderTargets::ulid($handle, $prefix);
        if (null === $ulid) {
            return ToolResult::error('invalid_input', 'malformed handle');
        }

        return \in_array($ulid, $owned, true) ? $prefix.$ulid : ToolResult::error('not_found', 'not owned');
    }
}
