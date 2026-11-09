<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\GetVehicleFactsInput;
use App\Entity\Vehicle;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleType;
use App\Maintenance\VehicleFactsProjector;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_vehicle_facts` tool: resolves a vehicle (shared garage)
 * and returns its facts with provenance, or a structured not_in_catalog result on a total
 * miss. The miss is a RESULT, never an exception, so it cannot surface as a -32603 error.
 *
 * @implements ProcessorInterface<GetVehicleFactsInput, CallToolResult>
 */
final readonly class GetVehicleFactsProcessor implements ProcessorInterface
{
    public function __construct(
        private GarageResolver $resolver,
        private FactView $factView,
        private IriConverterInterface $iriConverter,
        private VehicleIriResolver $iriResolver,
        private VehicleFactsProjector $factsProjector,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        // Addressed by @id (e.g. the dashboard's selected vehicle): dereference the handle straight
        // to its row — no make/model (the row already exists).
        if (null !== $data->vehicle) {
            return $this->factsByIri($data->vehicle, $data->keys);
        }

        $guard = $this->iriResolver->requireIdentity($data->make, $data->model);
        if (null !== $guard) {
            return $guard;
        }

        $type = VehicleType::from($data->vehicleType);
        $vehicle = $this->resolver->resolve($data->make, $data->model, $data->year, $type);

        $identity = [
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'vehicleType' => $data->vehicleType,
        ];

        if (null === $vehicle) {
            return ToolResult::of([
                'status' => 'not_in_catalog',
                'vehicle' => $identity,
                'missing' => $data->keys,
                'how_to_add' => [
                    'tool' => 'set_vehicle_facts',
                    'hint' => 'Record facts with set_vehicle_facts (vehicle = the vehicle @id, facts:[{key, value, source, url}]).',
                ],
            ]);
        }

        return $this->okResult($vehicle, $data->keys, $identity);
    }

    /**
     * Read facts for the vehicle at the given @id. A malformed IRI is invalid_input, an IRI that
     * resolves to nothing is not_found — never an exception, same "miss is a result" contract.
     *
     * @param list<string> $keys
     */
    private function factsByIri(string $iri, array $keys): CallToolResult
    {
        $vehicle = $this->iriResolver->resolveVehicle($iri);
        if ($vehicle instanceof CallToolResult) {
            return $vehicle;
        }

        return $this->okResult($vehicle, $keys, [
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'year' => $vehicle->getYear(),
            'vehicleType' => $vehicle->getVehicleType()->value,
        ]);
    }

    /**
     * @param list<string>         $keys     fact keys to keep ([] = all)
     * @param array<string, mixed> $identity make/model/year/vehicleType echoed back on the result
     */
    private function okResult(Vehicle $vehicle, array $keys, array $identity): CallToolResult
    {
        $facts = [];
        foreach ($this->factsProjector->forVehicle($vehicle) as $fact) {
            if ([] !== $keys && !\in_array($fact->getKey(), $keys, true)) {
                continue;
            }
            $facts[] = $this->factView->toArray($fact);
        }

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => ['@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle))] + $identity,
            'facts' => $facts,
        ]);
    }
}
