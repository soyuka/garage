<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\MotulOilInput;
use App\Entity\Vehicle;
use App\Exception\UpstreamException;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Motul\MotulOilAdvisor;
use App\Siv\SivClient;
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
        private readonly IriConverterInterface $iriConverter,
        private readonly GarageResolver $resolver,
        private readonly SivClient $siv,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $search = trim((string) $data->search);
        $year = $data->year;
        $engine = $data->engine;

        if (null !== $data->vehicle) {
            $vehicle = $this->resolveVehicle($data->vehicle);
            if (null === $vehicle) {
                return ToolResult::of([
                    'status' => 'not_found',
                    'reason' => sprintf('No vehicle at %s in the garage. Use an @id from list_vehicles.', $data->vehicle),
                ]);
            }

            // The row's identity + plate fill whatever the caller did not override.
            $search = '' !== $search ? $search : trim($vehicle->getMake().' '.$vehicle->getModel());
            $year ??= $vehicle->getYear();
            $engine ??= $this->engineFromPlate($vehicle);
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
            return ToolResult::of([
                'status' => 'upstream_error',
                'query' => $search,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function resolveVehicle(string $iri): ?Vehicle
    {
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ItemNotFoundException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        return $this->resolver->resolveById($resource->id);
    }

    /**
     * Best-effort engine displacement from the vehicle's plate (SIV `capacite_litres`, e.g. "1.8",
     * falling back to the `version` label). The engine is not a stored vehicle field; the plate is
     * the only place it lives. A miss (no plate / SIV down / no key set) yields null — the advisor
     * then narrows by year alone, never an error.
     */
    private function engineFromPlate(Vehicle $vehicle): ?string
    {
        $plate = $vehicle->getPlate();
        if (null === $plate || '' === trim($plate)) {
            return null;
        }

        try {
            $siv = $this->siv->lookup($plate);
        } catch (\Throwable) {
            return null;
        }

        if (null === $siv) {
            return null;
        }

        $displacement = trim((string) ($siv['capacite_litres'] ?? ''));
        if ('' !== $displacement) {
            return $displacement;
        }

        $version = trim((string) ($siv['version'] ?? ''));

        return '' !== $version ? $version : null;
    }
}
