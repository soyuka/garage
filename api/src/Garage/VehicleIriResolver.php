<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Repository\VehicleRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Shared vehicle-@id resolution for the MCP garage tools. Turns the caller-supplied `vehicle` IRI
 * into a vehicle ULID (or the dereferenced {@see GarageVehicle} read-model), mapping the two failure
 * modes onto the tools' canonical {@see ToolError}s — never an exception, so a bad handle reaches
 * the model as recoverable text instead of a -32603 protocol error:
 *  - a well-formed IRI that resolves to nothing  → not_found     "No vehicle at %s in the garage."
 *  - a malformed / non-vehicle IRI               → invalid_input '"%s" is not a valid vehicle @id…'.
 *
 * Centralises the wording so the (drift-prone) copies scattered across the write/read processors
 * stay in lockstep. {@see requireIdentity()} is the companion guard for the make+model fallback.
 */
final readonly class VehicleIriResolver
{
    public function __construct(
        private IriConverterInterface $iriConverter,
        private VehicleRepository $vehicles,
    ) {
    }

    /**
     * The caller's own live vehicle behind a @id, or the tool error to return. The one entity lookup a
     * tool processor may use after resolving an IRI: the owner and deleted floor is in the query.
     */
    public function resolveVehicle(string $iri): Vehicle|ToolError
    {
        $resolved = $this->resolve($iri);
        if ($resolved instanceof ToolError) {
            return $resolved;
        }

        return $this->vehicles->findOwned($resolved) ?? $this->notFound($iri);
    }

    /**
     * The vehicle ULID behind the IRI, or the canonical not_found/invalid_input result on failure.
     */
    public function resolve(string $iri): Ulid|ToolError
    {
        $resource = $this->resolveResource($iri);

        return $resource instanceof ToolError ? $resource : $resource->id;
    }

    /**
     * The dereferenced vehicle read-model behind the IRI (its identity fields, not just the id), or
     * the canonical not_found/invalid_input result. A non-vehicle IRI surfaces as invalid_input, same
     * as a malformed handle.
     */
    public function resolveResource(string $iri): GarageVehicle|ToolError
    {
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ItemNotFoundException) {
            return $this->notFound($iri);
        } catch (\Throwable) {
            return $this->invalidIri($iri);
        }

        return $resource instanceof GarageVehicle ? $resource : $this->invalidIri($iri);
    }

    private function invalidIri(string $iri): ToolError
    {
        return ToolResult::error('invalid_input', \sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $iri));
    }

    /**
     * The canonical "resolved to no row" result — reused both when the IRI dereferences to nothing
     * and when a subsequent repository lookup on the resolved id comes back empty.
     */
    public function notFound(string $iri): ToolError
    {
        return ToolResult::error('not_found', \sprintf('No vehicle at %s in the garage.', $iri));
    }

    /**
     * The make+model fallback guard: when no @id is given, both are required. Returns the canonical
     * invalid_input result when either is missing, or null to continue.
     */
    public function requireIdentity(?string $make, ?string $model): ?ToolError
    {
        return null === $make || null === $model ? $this->missingIdentity() : null;
    }

    public function missingIdentity(): ToolError
    {
        return ToolResult::error('invalid_input', 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.');
    }
}
