<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\Vehicle;
use Mcp\Schema\Result\CallToolResult;

/**
 * The dedup verdict shared by every vehicle write tool (plan / log / set_schedule / update /
 * dismiss): each carries a `status`, the `candidates` an ambiguous match surfaced, and the
 * `vehicleId` a not_found addressed. {@see WriteOutcomeToolResult} maps the two shared error
 * states onto the canonical MCP results so the processors stop hand-rolling the same match block.
 *
 * @property string        $status
 * @property list<Vehicle> $candidates
 * @property string|null   $vehicleId
 */
interface WriteOutcome
{
    /**
     * The canonical result for the shared error states, or null when the write succeeded (the
     * processor then renders its own success payload):
     *  - ambiguous → ambiguous_match with the mapped candidates and the tool-specific reason;
     *  - not_found → not_found with the addressed vehicleId.
     *
     * The reason is passed in because its tail differs per tool ("…to plan against a new vehicle",
     * "…to update it", none for dismiss); everything else is identical and lives here.
     */
    public function toToolResult(VehicleSummaryView $view, string $ambiguousReason): ?CallToolResult;
}
