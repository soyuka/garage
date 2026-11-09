<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ServiceScheduleItem;
use App\Dto\ServiceScheduleInput;
use App\Entity\ServiceInterval;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * POST /garage/vehicles/{vehicleId}/service-schedule — the dashboard's manual schedule write. Upserts
 * one recurring rule as source "user" (unverified), keyed on (type, action, component): a re-POST of
 * the same key corrects the cadence in place (keeping the rule's id), a new key appends. Mirrors
 * {@see GarageVehicleFactProcessor}; the agent owns the bulk schedule via set_maintenance_schedule.
 *
 * @implements ProcessorInterface<ServiceScheduleInput, ServiceScheduleItem>
 */
final readonly class ServiceScheduleItemProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ServiceScheduleItem
    {
        $id = $uriVariables['vehicleId'];
        $vehicle = $this->vehicles->findOwned($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $vehicle) {
            throw new NotFoundHttpException('No vehicle at this IRI.');
        }

        $action = MaintenanceAction::tryFrom($data->action);
        if (null === $action) {
            throw new UnprocessableEntityHttpException(\sprintf('action must be one of %s.', implode(', ', MaintenanceAction::values())));
        }

        $hasUsage = null !== $data->everyUsageValue;
        if ($hasUsage !== (null !== $data->everyUsageUnit)) {
            throw new UnprocessableEntityHttpException('everyUsageValue and everyUsageUnit must be provided together.');
        }
        if (!$hasUsage && null === $data->everyMonths && null === $data->firstUsageValue) {
            throw new UnprocessableEntityHttpException('A rule needs at least one cadence (everyUsageValue, everyMonths or firstUsageValue).');
        }

        $unit = null !== $data->everyUsageUnit ? UsageUnit::from($data->everyUsageUnit) : null;
        $component = '' === ($data->component ?? '') ? null : $data->component;

        // Resolve the rule by its dedup key (type:action:component); revise it in place when present so
        // the id stays stable for the row the dashboard is editing.
        $probe = new ServiceInterval($data->type, $action, 'user', component: $component);
        $rule = $vehicle->serviceIntervalByKey($probe->key());

        if (null === $rule) {
            $rule = ServiceInterval::fromSource(
                type: $data->type,
                action: $action,
                source: 'user',
                component: $component,
                everyUsageValue: $data->everyUsageValue,
                everyUsageUnit: $unit,
                everyMonths: $data->everyMonths,
                firstUsageValue: $data->firstUsageValue,
                url: $data->url,
            );
            $vehicle->setServiceInterval($rule);
        } else {
            $rule->revise($data->everyUsageValue, $unit, $data->everyMonths, $data->firstUsageValue, 'user', $data->url);
        }

        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return ServiceScheduleItem::fromEntity($rule, $vehicle->getId());
    }
}
