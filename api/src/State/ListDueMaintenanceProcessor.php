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
        'Due dates are time-based, general-knowledge interval defaults — not the manufacturer schedule.',
        'Items with a usageInterval also recur by distance/hours; the forecast cannot use it without a current reading — ask the user "what is the vehicle\'s current km/hours?" to refine.',
        'gaps lists recurring services with no logged history: their next-due is unknown. Ask when each was last done, or log the past event, to forecast it.',
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
                if (null !== $data->withinDays && $due->daysUntilDue > $data->withinDays) {
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
        usort($pairs, static fn (array $a, array $b): int => $a['due']->dueAt <=> $b['due']->dueAt);

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
