<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\GetMaintenanceHistoryInput;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\MaintenanceType;
use App\Maintenance\RecordView;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_maintenance_history` tool: returns a vehicle's service events, newest first,
 * optionally filtered by type. An unknown vehicle is a no_records RESULT (isError=false),
 * never an exception — same "miss is not an error" contract as the garage tools.
 *
 * @implements ProcessorInterface<GetMaintenanceHistoryInput, CallToolResult>
 */
final class GetMaintenanceHistoryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly MaintenanceRecorder $recorder,
        private readonly RecordView $recordView,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $vehicleId = null;
        $identity = [
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'vehicleType' => $data->vehicleType,
        ];

        // Addressed by @id (e.g. the dashboard's selected vehicle): dereference the handle and take
        // its identity from the row, so make/model are optional.
        if (null !== $data->vehicle) {
            try {
                $resource = $this->iriConverter->getResourceFromIri($data->vehicle);
            } catch (ItemNotFoundException) {
                return ToolResult::of(['status' => 'not_found', 'reason' => sprintf('No vehicle at %s in the garage.', $data->vehicle)]);
            } catch (\Throwable) {
                return ToolResult::of(['status' => 'invalid_input', 'reason' => sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $data->vehicle)]);
            }
            $vehicleId = $resource->id;
            $identity = [
                'make' => $resource->make,
                'model' => $resource->model,
                'year' => $resource->year,
                'vehicleType' => $resource->vehicleType,
            ];
        } elseif (null === $data->make || null === $data->model) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.',
            ]);
        }

        $records = $this->recorder->history(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            type: null !== $data->type ? MaintenanceType::from($data->type) : null,
            limit: $data->limit,
        );

        if (null === $records) {
            return ToolResult::of([
                'status' => 'no_records',
                'vehicle' => $identity,
            ]);
        }

        if ([] !== $records) {
            $identity = ['@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($records[0]->getVehicle()))] + $identity;
        }

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => $identity,
            'records' => array_map($this->recordView->toArray(...), $records),
        ]);
    }
}
