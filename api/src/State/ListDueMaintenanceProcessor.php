<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ListDueMaintenanceInput;
use App\Entity\Vehicle;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use App\Maintenance\DueMaintenance;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\DueMaintenanceView;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_due_maintenance` tool: forecasts upcoming/overdue services across the shared garage,
 * soonest due first. It deliberately reports both halves of the truth — `due` (what we CAN
 * predict from history) and `gaps` (recurring services with no logged history, so we honestly
 * cannot) — and the `notes` spell out that due dates are time-based and that a current
 * odometer/hour reading is needed to refine the usage axis.
 *
 * @implements ProcessorInterface<ListDueMaintenanceInput, CallToolResult>
 */
final class ListDueMaintenanceProcessor implements ProcessorInterface
{
    private const array NOTES = [
        'A due item with rule "schedule" comes from a per-vehicle recurring rule (set_maintenance_schedule); rule "default" is a general-knowledge time interval, not the manufacturer schedule.',
        'The `usage` block (last/due/remaining) is the hours/km axis — what matters for hour-metered machinery. `projectedDueAt` lifts it onto the calendar when enough readings give a usage rate; otherwise only `remaining` is known.',
        'When both a time and a usage cadence apply, whichever comes first binds — `overdue` is true if either axis is breached.',
        'A generic `usageInterval` (rule "default") is advisory: the forecast cannot use it without a current reading — ask "what is the vehicle\'s current km/hours?" and set_maintenance_schedule to refine.',
        'gaps lists recurring services with no logged baseline: their next-due is unknown even when the cadence is known. Ask when each was last done, or log the past event, to forecast it.',
    ];

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DueMaintenanceCalculator $calculator,
        private readonly DueMaintenanceView $dueView,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $vehicles = $this->vehicles->findShared(
            $data->make,
            $data->model,
            null !== $data->vehicleType ? VehicleType::from($data->vehicleType) : null,
        );

        /** @var list<array{vehicle: Vehicle, due: DueMaintenance}> $pairs */
        $pairs = [];
        $gaps = [];
        foreach ($vehicles as $vehicle) {
            $forecast = $this->calculator->forVehicle($vehicle);

            foreach ($forecast->due as $due) {
                // A usage-only rule with no calendar projection has no day count — it cannot be
                // windowed out, so it always shows (its remaining-hours carry the urgency instead).
                if (null !== $data->withinDays && null !== $due->daysUntilDue && $due->daysUntilDue > $data->withinDays) {
                    continue;
                }
                $pairs[] = ['vehicle' => $vehicle, 'due' => $due];
            }

            if ([] !== $forecast->unknownBaseline) {
                $gaps[] = [
                    'vehicle' => $this->dueView->identity($vehicle),
                    'unknownBaseline' => array_map(static fn ($type): string => $type->value, $forecast->unknownBaseline),
                ];
            }
        }

        // Garage-wide ordering: the soonest due (most overdue) across all vehicles comes first.
        // Sort on the effective date (time axis, else usage-rate projection); undated usage-only
        // items sort last.
        usort($pairs, static function (array $a, array $b): int {
            $da = $a['due']->effectiveDueAt();
            $db = $b['due']->effectiveDueAt();

            return match (true) {
                null === $da && null === $db => 0,
                null === $da => 1,
                null === $db => -1,
                default => $da <=> $db,
            };
        });

        if (null !== $data->limit) {
            $pairs = \array_slice($pairs, 0, max(0, $data->limit));
        }

        $due = array_map(
            fn (array $pair): array => $this->dueView->dueItem($pair['vehicle'], $pair['due']),
            $pairs,
        );

        return ToolResult::of([
            'status' => 'ok',
            'count' => \count($due),
            'due' => $due,
            'gaps' => $gaps,
            'notes' => self::NOTES,
        ]);
    }
}
