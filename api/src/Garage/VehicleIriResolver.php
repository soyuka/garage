<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicle;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Uid\Ulid;

/**
 * Shared vehicle-@id resolution for the MCP garage tools. Turns the caller-supplied `vehicle` IRI
 * into a vehicle ULID (or the dereferenced {@see GarageVehicle} read-model), mapping the two failure
 * modes onto the tools' canonical structured results — never an exception, so a bad handle reaches
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
    ) {
    }

    /**
     * The vehicle ULID behind the IRI, or the canonical not_found/invalid_input result on failure.
     */
    public function resolve(string $iri): Ulid|CallToolResult
    {
        $resource = $this->resolveResource($iri);

        return $resource instanceof CallToolResult ? $resource : $resource->id;
    }

    /**
     * The dereferenced vehicle read-model behind the IRI (its identity fields, not just the id), or
     * the canonical not_found/invalid_input result. A non-vehicle IRI trips the return type check
     * inside the try and surfaces as invalid_input, same as a malformed handle.
     */
    public function resolveResource(string $iri): GarageVehicle|CallToolResult
    {
        try {
            return $this->iriConverter->getResourceFromIri($iri);
        } catch (ItemNotFoundException) {
            return $this->notFound($iri);
        } catch (\Throwable) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => \sprintf('"%s" is not a valid vehicle @id. Use an @id from list_vehicles.', $iri),
            ]);
        }
    }

    /**
     * The canonical "resolved to no row" result — reused both when the IRI dereferences to nothing
     * and when a subsequent repository lookup on the resolved id comes back empty.
     */
    public function notFound(string $iri): CallToolResult
    {
        return ToolResult::of([
            'status' => 'not_found',
            'reason' => \sprintf('No vehicle at %s in the garage.', $iri),
        ]);
    }

    /**
     * The make+model fallback guard: when no @id is given, both are required. Returns the canonical
     * invalid_input result when either is missing, or null to continue.
     */
    public function requireIdentity(?string $make, ?string $model): ?CallToolResult
    {
        if (null === $make || null === $model) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide make and model, or a vehicle @id (IRI) from list_vehicles.',
            ]);
        }

        return null;
    }
}
