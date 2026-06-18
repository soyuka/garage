<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\GetVehicleFactsInput;
use App\Entity\Vehicle;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use App\Maintenance\VehicleFactsProjector;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_vehicle_facts` tool: resolves a vehicle (shared garage → upstream write-through)
 * and returns its facts with provenance, or a structured not_in_catalog result on a total
 * miss. The miss is a RESULT, never an exception, so it cannot surface as a -32603 error.
 *
 * @implements ProcessorInterface<GetVehicleFactsInput, CallToolResult>
 */
final class GetVehicleFactsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GarageResolver $resolver,
        private readonly FactView $factView,
        private readonly IriConverterInterface $iriConverter,
        private readonly VehicleFactsProjector $factsProjector,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        // Addressed by @id (e.g. the dashboard's selected vehicle): dereference the handle straight
        // to its row — no make/model, no catalog write-through (the row already exists).
        if (null !== $data->vehicle) {
            return $this->factsByIri($data->vehicle, $data->keys);
        }

        if (null === $data->make || null === $data->model) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.',
            ]);
        }

        $type = VehicleType::from($data->vehicleType);
        $vehicle = $this->resolver->resolve($data->make, $data->model, $data->year, $type, $data->carId);

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
                    'tool' => 'plan_maintenance',
                    'hint' => 'Facts are recorded as `attributes` on a maintenance item — plan the relevant service and pass attributes:[{key, value, source, url}]. There is no separate add-fact tool.',
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
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ItemNotFoundException) {
            return ToolResult::of(['status' => 'not_found', 'reason' => sprintf('No vehicle at %s in the garage.', $iri)]);
        } catch (\Throwable) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $iri)]);
        }

        $vehicle = $this->resolver->resolveById($resource->id);
        if (null === $vehicle) {
            return ToolResult::of(['status' => 'not_found', 'reason' => sprintf('No vehicle at %s in the garage.', $iri)]);
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
        // Self-heal a row whose kType was known but whose catalog spec sheet was never fetched
        // (added by plate lookup / update_vehicle) — so a fact query by @id no longer comes back
        // empty when the catalog has the answer. No-op when already filled or the kType is unknown.
        $this->resolver->backfillCatalogSpecs($vehicle);

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
