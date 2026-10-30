<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Bonneref\BonnerefClient;
use App\Dto\MotulOilInput;
use App\Entity\Vehicle;
use App\Exception\UpstreamException;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Motul\MotulOilAdvisor;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_motul_oil` tool. An upstream failure (Motul down / format shift) is returned as a
 * structured result, never thrown, so it reaches the model as recoverable text instead of a
 * -32603 protocol error that would abort the turn.
 *
 * Addressed by a vehicle @id, the variant is pinned from data the garage already holds — make/model
 * + year from the row, engine from the stored plate's SIV record — instead of relying on the model
 * to pass them. Omitting year+engine left the advisor permanently "ambiguous" (a model spans many
 * engines), so it never reached a grade; deriving them server-side closes that failure class. The
 * free-text search/year/engine fields remain as overrides (and for vehicles with no stored handle).
 *
 * @implements ProcessorInterface<MotulOilInput, CallToolResult>
 */
final class MotulOilProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly MotulOilAdvisor $advisor,
        private readonly VehicleIriResolver $iriResolver,
        private readonly GarageResolver $resolver,
        private readonly BonnerefClient $bonneref,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $search = trim((string) $data->search);
        $year = $data->year;
        $engine = $data->engine;

        // Most specific handle: an exact Motul variant id picked from a prior ambiguous result.
        // Pins the row directly — no search, no @id/SIV derivation — so a CTA click converges.
        $motulId = trim((string) $data->motulId);
        if ('' !== $motulId) {
            try {
                return ToolResult::of($this->advisor->adviseVariant($motulId));
            } catch (UpstreamException $e) {
                return $this->upstreamError($motulId, $e);
            }
        }

        if (null !== $data->vehicle) {
            $vehicle = $this->resolveVehicle($data->vehicle);
            if (null === $vehicle) {
                return ToolResult::of([
                    'status' => 'not_found',
                    'reason' => sprintf('No vehicle at %s in the garage. Use an @id from list_vehicles.', $data->vehicle),
                ]);
            }

            // The row's identity + plate fill whatever the caller did not override. The stored engine
            // variant (e.g. "2.0 16V Coupé") is the strongest engine hint — it carries the body the
            // plate's bare displacement cannot — so it is preferred; the plate's SIV record is the
            // fallback for a vehicle whose engineVariant was never set.
            $search = '' !== $search ? $search : trim($vehicle->getMake().' '.$vehicle->getModel());
            $year ??= $vehicle->getYear();
            $engine ??= $vehicle->getEngineVariant() ?? $this->engineFromPlate($vehicle);
        }

        if ('' === $search) {
            return ToolResult::of([
                'status' => 'invalid_input',
                'reason' => 'Provide a vehicle @id, or a search like "Mazda 6".',
            ]);
        }

        try {
            return ToolResult::of($this->advisor->advise($search, $year, $engine));
        } catch (UpstreamException $e) {
            return $this->upstreamError($search, $e);
        }
    }

    private function upstreamError(string $query, UpstreamException $e): CallToolResult
    {
        return ToolResult::of([
            'status' => 'upstream_error',
            'query' => $query,
            'reason' => $e->getMessage(),
        ]);
    }

    private function resolveVehicle(string $iri): ?Vehicle
    {
        $resource = $this->iriResolver->resolveResource($iri);
        if ($resource instanceof CallToolResult) {
            return null;
        }

        return $this->resolver->resolveById($resource->id);
    }

    /**
     * Best-effort engine displacement from the vehicle's plate (the registry's cylinder capacity in litres,
     * e.g. "1.8", falling back to the `version` label). The engine is not a stored vehicle field; the plate
     * is the only place it lives. A miss (no plate / bonneref down / unknown plate) yields null — the advisor
     * then narrows by year alone, never an error.
     */
    private function engineFromPlate(Vehicle $vehicle): ?string
    {
        $plate = $vehicle->getPlate();
        if (null === $plate || '' === trim($plate)) {
            return null;
        }

        try {
            $registered = $this->bonneref->vehicleByPlate($plate);
        } catch (\Throwable) {
            return null;
        }

        if (null === $registered) {
            return null;
        }

        if (null !== $registered->engineCapacityCcm && $registered->engineCapacityCcm > 0) {
            return number_format($registered->engineCapacityCcm / 1000, 1, '.', '');
        }

        return $registered->version;
    }
}
