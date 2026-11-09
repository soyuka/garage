<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\ApiResource\GarageVehicle;
use App\Dto\Dashboard\OpenVehicleInput;
use App\Entity\Vehicle;
use App\Garage\ResourceIri;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\DueMaintenanceCalculator;
use App\Maintenance\DueMaintenanceView;
use App\Maintenance\PlannedTaskView;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `open_vehicle`: ONE call where a pilot run used to need four.
 *
 * Beat 1 of the conference (« qu'est-ce que j'ai à faire sur la Mazda ? ») cost list_vehicles (to turn
 * the spoken name into an @id) → select_vehicle → list_due_maintenance → list_planned_maintenance.
 * Each is a model round trip, and the room watches every one of them as a still screen. None of the
 * four needed the model's judgement: the second is mechanical, the last two are reads whose arguments
 * were already fixed by the first. So they collapse here — resolve, drive the car in, and hand back
 * the whole picture in one payload.
 *
 * It composes the existing seams verbatim rather than reimplementing them ({@see RenderIntentTools}
 * for the frame, {@see DueMaintenanceCalculator} for the forecast, {@see Vehicle::openTasks()} for
 * the checklist), so its answers cannot drift from the granular tools'.
 *
 * NEVER asks a clarifying question: several matches resolve to the most-recently-touched one, and the
 * runners-up come back in `otherMatches` so the pilot can say which car it took.
 *
 * @implements ProcessorInterface<OpenVehicleInput, CallToolResult>
 */
final readonly class OpenVehicleProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private RenderIntentTools $render,
        private DueMaintenanceCalculator $calculator,
        private DueMaintenanceView $dueView,
        private PlannedTaskView $taskView,
        private VehicleSummaryView $summaryView,
        private IriConverterInterface $iriConverter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $query = trim($data->query);
        if ('' === $query) {
            return ToolResult::error('invalid_input', 'query must name the vehicle — a make, model, plate or VIN, e.g. "mazda" or "AB-123-CD".');
        }

        $matches = $this->vehicles->searchShared(
            $query,
            null !== $data->vehicleType ? VehicleType::from($data->vehicleType) : null,
        );

        if ([] === $matches) {
            // A miss is a RESULT, not an error — the same contract every garage read tool keeps.
            return ToolResult::of([
                'status' => 'no_match',
                'query' => $query,
                'hint' => 'No vehicle of this garage matches that name. Call list_vehicles to see what exists.',
            ]);
        }

        $vehicle = $matches[0];
        $iri = ResourceIri::of($this->iriConverter, GarageVehicle::fromEntity($vehicle));

        // Drive the car in FIRST: the render intent is the answer, and it should be on screen while
        // the model is still reading the forecast below it.
        $this->render->selectVehicle($iri);

        $forecast = $this->calculator->forVehicle($vehicle);

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => $this->summaryView->toArray($vehicle),
            'due' => array_map(fn ($due): array => $this->dueView->item($due), $forecast->due),
            'gaps' => array_map(static fn ($type): string => $type->value, $forecast->unknownBaseline),
            'tasks' => array_map($this->taskView->toArray(...), $vehicle->openTasks()),
            'otherMatches' => array_map($this->label(...), \array_slice($matches, 1)),
            'rendered' => 'selectVehicle',
        ]);
    }

    /** @return array{'@id': string, label: string} */
    private function label(Vehicle $vehicle): array
    {
        return [
            '@id' => ResourceIri::of($this->iriConverter, GarageVehicle::fromEntity($vehicle)),
            'label' => trim($vehicle->getMake().' '.$vehicle->getModel()),
        ];
    }
}
