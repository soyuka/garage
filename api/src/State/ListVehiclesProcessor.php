<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\SelectedVehicleContext;
use App\Dto\ListVehiclesInput;
use App\Entity\Vehicle;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_vehicles` tool: enumerates the current owner's garage as identity summaries with a
 * factCount, ordered MOST-RECENTLY-TOUCHED first (see {@see VehicleRepository::findShared()}) and
 * PAGINATED (limit/offset) — the server-side echo of what the dashboard sidebar shows. The vehicle
 * the dashboard currently has SELECTED (carried via {@see SelectedVehicleContext}) is floated to the
 * top of the first page so the model works against the one in focus. An empty garage is an ok result
 * with count 0 — never a miss or an error.
 *
 * @implements ProcessorInterface<ListVehiclesInput, CallToolResult>
 */
final readonly class ListVehiclesProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleSummaryView $summaryView,
        private SelectedVehicleContext $selected,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $type = null !== $data->vehicleType ? VehicleType::from($data->vehicleType) : null;
        $limit = max(1, min(ListVehiclesInput::MAX_LIMIT, $data->limit));
        $offset = max(0, $data->offset);

        $total = $this->vehicles->countShared($data->make, $data->model, $type);
        $page = $this->vehicles->findShared($data->make, $data->model, $type, $limit, $offset);

        // Selected-first, but only on the first page and only when the listing is not narrowed by a
        // filter — a deliberate filtered search should not be reshuffled by the dashboard's focus.
        if (0 === $offset && null === $data->make && null === $data->model && null === $type) {
            $page = $this->floatSelectedFirst($page);
        }

        $summaries = array_map($this->summaryView->toArray(...), $page);

        return ToolResult::of([
            'status' => 'ok',
            'total' => $total,
            'count' => \count($summaries),
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => $offset + \count($summaries) < $total,
            'vehicles' => $summaries,
        ]);
    }

    /**
     * Reorder the (owner-scoped) page so the selected vehicle leads. Reorder only — never fetches a
     * vehicle from outside the page, so it can leak nothing across tenants.
     *
     * @param list<Vehicle> $page
     *
     * @return list<Vehicle>
     */
    private function floatSelectedFirst(array $page): array
    {
        $iri = $this->selected->current();
        if (null === $iri) {
            return $page;
        }

        $selectedId = substr($iri, strrpos($iri, '/') + 1);

        $selectedVehicle = null;
        $rest = [];
        foreach ($page as $vehicle) {
            if ($vehicle->getId()->toBase32() === $selectedId) {
                $selectedVehicle = $vehicle;
            } else {
                $rest[] = $vehicle;
            }
        }

        return null !== $selectedVehicle ? array_merge([$selectedVehicle], $rest) : $page;
    }
}
