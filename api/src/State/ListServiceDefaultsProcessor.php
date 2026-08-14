<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ListServiceDefaultsInput;
use App\Entity\Vehicle;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Maintenance\Defaults\ServiceBaseline;
use App\Maintenance\Defaults\ServiceDefaultsCatalog;
use App\Maintenance\Defaults\ServiceDefaultsResolver;
use App\Maintenance\Defaults\ServiceDefaultsView;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_service_defaults` tool: reads the generic-baseline catalogue, resolved against a vehicle
 * when one is given. Strictly READ-ONLY and strictly ADVISORY — it touches no vehicle's
 * serviceSchedule and feeds no forecast; turning a baseline into a rule stays an explicit
 * set_maintenance_schedule call the user agrees to.
 *
 * The `notes` carry the two rules the model must not lose: the precedence (user > per-vehicle rule >
 * baseline) and the fact that an `ambiguous` result is a question, not a menu to pick from.
 *
 * @implements ProcessorInterface<ListServiceDefaultsInput, CallToolResult>
 */
final class ListServiceDefaultsProcessor implements ProcessorInterface
{
    private const array NOTES = [
        'PRECEDENCE, highest first: what the user stated > this vehicle\'s own stored rule (set_maintenance_schedule) or verified fact > these generic baselines. A baseline NEVER outranks a stored rule — if the vehicle already has one, use it and ignore this.',
        'ADVISORY ONLY: nothing here is attached to any vehicle, and none of it feeds list_due_maintenance. To make a baseline real, confirm it with the user and store it with set_maintenance_schedule.',
        'status "ambiguous" means the interval depends on a fact this vehicle does not carry. ASK the user, or look that fact up and record it — never pick one of the candidates and never average them.',
        '"hasInterval": false is an ANSWER, not missing data: that part has no scheduled replacement (a timing chain, a petrol in-tank fuel filter). Do not go looking for a number for it.',
        '"unsourced": true means trade consensus with no citable source — present it as general knowledge, never as the manufacturer\'s schedule.',
    ];

    public function __construct(
        private readonly ServiceDefaultsCatalog $catalog,
        private readonly ServiceDefaultsResolver $resolver,
        private readonly ServiceDefaultsView $view,
        private readonly VehicleIriResolver $iriResolver,
        private readonly GarageResolver $garage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $vehicle = null;
        if (null !== $data->vehicle) {
            $resolved = $this->resolveVehicle($data->vehicle);
            if ($resolved instanceof CallToolResult) {
                return $resolved;
            }
            $vehicle = $resolved;
        }

        $identity = null === $vehicle ? null : [
            '@id' => $data->vehicle,
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'vehicleType' => $vehicle->getVehicleType()->value,
        ];

        if (null === $data->type) {
            return $this->listing($vehicle, $identity);
        }

        return ToolResult::of(
            $this->view->resolution($this->resolver->resolve($data->type, $vehicle))
            + ['vehicle' => $identity, 'notes' => self::NOTES],
        );
    }

    /**
     * The whole catalogue, scoped to the vehicle's segment when one is given — a mower must never be
     * shown a car's distance cadences.
     *
     * @param array<string, mixed>|null $identity
     */
    private function listing(?Vehicle $vehicle, ?array $identity): CallToolResult
    {
        $baselines = $this->catalog->all();
        if (null !== $vehicle) {
            $segment = $vehicle->getVehicleType();
            $baselines = array_values(array_filter(
                $baselines,
                static fn (ServiceBaseline $b): bool => $b->appliesTo($segment),
            ));
        }

        return ToolResult::of([
            'status' => 'ok',
            'count' => \count($baselines),
            'vehicle' => $identity,
            'types' => array_values(array_unique(array_map(static fn (ServiceBaseline $b): string => $b->type, $baselines))),
            'baselines' => $this->view->baselines($baselines),
            'notes' => self::NOTES,
        ]);
    }

    /**
     * A bad handle comes back as the canonical invalid_input/not_found RESULT, never an exception —
     * same contract as the other garage tools.
     */
    private function resolveVehicle(string $iri): Vehicle|CallToolResult
    {
        $resolved = $this->iriResolver->resolve($iri);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        return $this->garage->resolveById($resolved) ?? $this->iriResolver->notFound($iri);
    }
}
