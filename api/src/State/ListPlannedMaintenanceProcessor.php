<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\ListPlannedMaintenanceInput;
use App\Entity\Vehicle;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleType;
use App\Maintenance\PlannedTaskView;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_planned_maintenance` tool: returns a vehicle's OPEN, actionable checklist — the live
 * "Entretien en cours" todo with its parts (shopping list) and spec attributes. A pure READ
 * (findByIdentity, never the write-through resolver), so an unknown vehicle is a no_records RESULT
 * (isError=false), not an exception and never a freshly-created row — same "miss is not an error"
 * contract as get_maintenance_history. The open+actionable filter mirrors {@see Vehicle::openTasks()}:
 * done/dismissed tasks and KNOWN spec carriers drop off the checklist.
 *
 * @implements ProcessorInterface<ListPlannedMaintenanceInput, CallToolResult>
 */
final readonly class ListPlannedMaintenanceProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private PlannedTaskView $taskView,
        private IriConverterInterface $iriConverter,
        private VehicleIriResolver $iriResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $identity = [
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'vehicleType' => $data->vehicleType,
        ];

        // Addressed by @id (e.g. the dashboard's selected vehicle): dereference the handle straight
        // to its row, so make/model are optional.
        if (null !== $data->vehicle) {
            $vehicle = $this->iriResolver->resolveVehicle($data->vehicle);
            if ($vehicle instanceof CallToolResult) {
                return $vehicle;
            }

            return $this->okResult($vehicle);
        }

        $guard = $this->iriResolver->requireIdentity($data->make, $data->model);
        if (null !== $guard) {
            return $guard;
        }

        $vehicle = $this->vehicles->findByIdentity($data->make, $data->model, VehicleType::from($data->vehicleType), $data->year);
        if (null === $vehicle) {
            return ToolResult::of([
                'status' => 'no_records',
                'vehicle' => $identity,
            ]);
        }

        return $this->okResult($vehicle);
    }

    private function okResult(Vehicle $vehicle): CallToolResult
    {
        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => [
                '@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle)),
                'make' => $vehicle->getMake(),
                'model' => $vehicle->getModel(),
                'year' => $vehicle->getYear(),
                'vehicleType' => $vehicle->getVehicleType()->value,
            ],
            'tasks' => array_map($this->taskView->toArray(...), $vehicle->openTasks()),
        ]);
    }
}
