<?php

declare(strict_types=1);

namespace App\Garage;

use Mcp\Schema\Result\CallToolResult;

/**
 * Shared {@see WriteOutcome::toToolResult()} implementation for the vehicle write outcomes. Reads the
 * `status`/`candidates`/`vehicleId` every one of them declares; the host class owns its success
 * payload (the `default` case here returns null to signal "keep going").
 *
 * @require-implements WriteOutcome
 */
trait WriteOutcomeToolResult
{
    public function toToolResult(VehicleSummaryView $view, string $ambiguousReason): ?CallToolResult
    {
        return match ($this->status) {
            'ambiguous' => ToolResult::error('ambiguous_match', $ambiguousReason, [
                'candidates' => array_map($view->toArray(...), $this->candidates),
            ]),
            'not_found' => ToolResult::error('not_found', \sprintf('No vehicle %s in the garage.', $this->vehicleId)),
            default => null,
        };
    }
}
