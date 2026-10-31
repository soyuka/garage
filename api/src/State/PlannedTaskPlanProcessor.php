<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlannedTask;
use App\Dto\PlanMaintenanceInput;
use App\Entity\PlannedPart;
use App\Garage\UsageUnit;
use App\Garage\VehicleIriResolver;
use App\Garage\VehicleSummaryView;
use App\Garage\VehicleType;
use App\Maintenance\PlannedMaintenancePlanner;
use App\Mercure\DomainPublisher;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /garage/planned-tasks — the REST face of plan_maintenance (the finder's "add to my garage").
 * Same planner and vehicle dedup as the MCP tool, with HTTP semantics: 201 + the task (its `vehicle`
 * IRI deep-links the Atelier), 409 + candidates on an ambiguous match, 404 on an unknown vehicle
 * IRI, 422 on invalid input.
 *
 * @implements ProcessorInterface<PlanMaintenanceInput, PlannedTask|Response>
 */
final readonly class PlannedTaskPlanProcessor implements ProcessorInterface
{
    public function __construct(
        private PlannedMaintenancePlanner $planner,
        private VehicleIriResolver $iriResolver,
        private VehicleSummaryView $summaryView,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlannedTask|Response
    {
        if (null === $data->vehicle) {
            $guard = $this->iriResolver->requireIdentity($data->make, $data->model);
            if (null !== $guard) {
                throw new UnprocessableEntityHttpException($guard->structuredContent['reason']);
            }
        }

        if ((null === $data->targetUsageValue) !== (null === $data->targetUsageUnit)) {
            throw new UnprocessableEntityHttpException('targetUsageValue and targetUsageUnit must be provided together.');
        }

        $targetUnit = null;
        if (null !== $data->targetUsageUnit) {
            $targetUnit = UsageUnit::tryFrom($data->targetUsageUnit)
                ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown targetUsageUnit "%s"; expected km, mi or h.', $data->targetUsageUnit));
        }

        foreach ($data->parts as $part) {
            // @phpstan-ignore function.alreadyNarrowedType, notIdentical.alwaysTrue (deserialized input: the phpdoc shape is the advertised schema, not a runtime guarantee)
            if (\is_array($part) && \array_key_exists('findRoute', $part) && null !== $part['findRoute'] && !PlannedPart::isValidFindRoute($part['findRoute'])) {
                throw new UnprocessableEntityHttpException('Invalid findRoute on a part.');
            }
        }

        $vehicleType = VehicleType::tryFrom($data->vehicleType)
            ?? throw new UnprocessableEntityHttpException(\sprintf('Unknown vehicleType "%s".', $data->vehicleType));

        $vehicleId = null;
        if (null !== $data->vehicle) {
            $resolved = $this->iriResolver->resolve($data->vehicle);
            if ($resolved instanceof CallToolResult) {
                $reason = $resolved->structuredContent['reason'];
                throw 'not_found' === $resolved->structuredContent['status'] ? new NotFoundHttpException($reason) : new UnprocessableEntityHttpException($reason);
            }
            $vehicleId = $resolved;
        }

        $outcome = $this->planner->plan(
            vehicleId: $vehicleId,
            make: $data->make,
            model: $data->model,
            year: $data->year,
            vehicleType: $vehicleType,
            carId: $data->carId,
            plate: $data->plate,
            vin: $data->vin,
            confirmNew: $data->confirmNew,
            type: $data->type,
            source: $data->source,
            label: $data->label,
            targetUsageValue: $data->targetUsageValue,
            targetUsageUnit: $targetUnit,
            dueDate: null !== $data->dueDate ? new \DateTimeImmutable($data->dueDate) : null,
            parts: $data->parts,
            addedBy: $data->addedBy,
        );

        if ('not_found' === $outcome->status) {
            throw new NotFoundHttpException(\sprintf('No vehicle %s in the garage.', $outcome->vehicleId));
        }

        if ('ambiguous' === $outcome->status) {
            return new JsonResponse([
                'status' => 'ambiguous_match',
                'reason' => 'One or more stored vehicles may be the same one. Re-send with vehicle set to the matching candidate\'s @id, or confirmNew:true to plan against a new vehicle.',
                'candidates' => array_map($this->summaryView->toArray(...), $outcome->candidates),
            ], Response::HTTP_CONFLICT);
        }

        $this->publisher->publishVehicle($outcome->task->getVehicle());

        return PlannedTask::fromEntity($outcome->task);
    }
}
