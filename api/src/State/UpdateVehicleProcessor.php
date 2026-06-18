<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UpdateVehicleInput;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\UsageUnit;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Uid\Ulid;

/**
 * MCP `update_vehicle` tool: patches a vehicle's own scalar columns (usage reading, owner, plate,
 * vin, year, modification) on the shared vehicle (creating it if absent), leaving omitted fields
 * unchanged. Identity/dedup mirrors plan_maintenance (make+model or @id, with ambiguous_match and
 * confirmNew). Returns structured invalid_input/not_found/ambiguous_match results — never throws,
 * so nothing leaks as a -32603 error.
 *
 * @implements ProcessorInterface<UpdateVehicleInput, CallToolResult>
 */
final class UpdateVehicleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GarageResolver $resolver,
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleSummaryView $summaryView,
        private readonly DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle && (null === $data->make || null === $data->model)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.',
            ]);
        }

        if ((null === $data->usageValue) !== (null === $data->usageUnit)) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'usageValue and usageUnit must be provided together.',
            ]);
        }

        $vehicleId = null;
        if (null !== $data->vehicle) {
            try {
                $vehicleId = $this->iriConverter->getResourceFromIri($data->vehicle)->id;
            } catch (ItemNotFoundException) {
                return ToolResult::of([
                    'status' => 'not_found',
                    'reason' => sprintf('No vehicle at %s in the garage.', $data->vehicle),
                ]);
            } catch (\Throwable) {
                return ToolResult::of([
                    'status' => 'invalid_input',
                    'reason' => sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $data->vehicle),
                ]);
            }
        }

        $outcome = $this->resolver->updateVehicle(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            usageValue: $data->usageValue,
            usageUnit: null !== $data->usageUnit ? UsageUnit::from($data->usageUnit) : null,
            owner: $data->owner,
            modification: $data->modification,
        );

        return match ($outcome->status) {
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call update_vehicle with vehicle set to the matching candidate\'s @id to update it, or pass confirmNew:true to store this as a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle %s in the garage.', $outcome->vehicleId),
            ]),
            default => $this->publishedVehicle($outcome->vehicle),
        };
    }

    private function publishedVehicle(Vehicle $vehicle): CallToolResult
    {
        $this->publisher->publishVehicle($vehicle);

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => $this->summaryView->toArray($vehicle),
        ]);
    }
}
