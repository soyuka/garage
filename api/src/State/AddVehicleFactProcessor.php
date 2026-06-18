<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\AddVehicleFactInput;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Uid\Ulid;

/**
 * MCP `add_vehicle_fact` tool: writes an unverified llm/user fact onto the shared vehicle
 * (creating it if absent). When the target key already holds a verified authoritative fact,
 * returns a structured refusal naming the existing fact — never overwrites, never throws,
 * so the precedence rule cannot leak as a -32603 error.
 *
 * @implements ProcessorInterface<AddVehicleFactInput, CallToolResult>
 */
final class AddVehicleFactProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GarageResolver $resolver,
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleSummaryView $summaryView,
        private readonly FactView $factView,
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

        $outcome = $this->resolver->addFact(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: VehicleType::from($data->vehicleType),
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            key: $data->key,
            value: $data->value,
            unit: $data->unit,
            source: $data->source,
            url: $data->url,
            addedBy: $data->addedBy,
        );

        return match ($outcome->status) {
            'refused' => ToolResult::of([
                'status' => 'refused',
                'reason' => sprintf(
                    'A verified %s fact already exists for "%s"; it is authoritative and is not overwritten by %s input.',
                    $outcome->fact->getSource(),
                    $data->key,
                    $data->source,
                ),
                'existing' => $this->factView->toArray($outcome->fact),
            ]),
            'ambiguous' => ToolResult::of([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-call add_vehicle_fact with vehicle set to the matching candidate\'s @id to update it, or pass confirmNew:true to store this as a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ]),
            'not_found' => ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No vehicle %s in the garage.', $outcome->vehicleId),
            ]),
            default => $this->publishedFact($outcome->vehicle, $outcome->fact),
        };
    }

    private function publishedFact(Vehicle $vehicle, VehicleFact $fact): CallToolResult
    {
        $this->publisher->publishVehicle($vehicle);

        return ToolResult::of([
            'status' => 'ok',
            'fact' => $this->factView->toArray($fact),
        ]);
    }
}
