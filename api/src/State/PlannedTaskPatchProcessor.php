<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlannedTask;
use App\Garage\UsageUnit;
use App\Maintenance\CalendarDate;
use App\Maintenance\OwnedPlannedTaskLocator;
use App\Maintenance\PlannedMaintenanceStatus;
use App\Mercure\DomainPublisher;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * The dashboard tick-off write: flips a planned task's lifecycle (status is the only writable field).
 * "done" stamps completedAt; "dismissed" drops it without crediting the work; "open" reopens it.
 * After the flush it publishes the owning vehicle over Mercure so the checklist live-updates.
 *
 * @implements ProcessorInterface<PlannedTask, PlannedTask>
 */
final readonly class PlannedTaskPatchProcessor implements ProcessorInterface
{
    public function __construct(
        private OwnedPlannedTaskLocator $locator,
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlannedTask
    {
        $id = $uriVariables['id'];
        $task = $this->locator->find($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $task) {
            throw new NotFoundHttpException('No planned task at this IRI.');
        }

        $status = PlannedMaintenanceStatus::tryFrom($data->status);
        if (null === $status) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown status "%s"; expected open, done or dismissed.', $data->status));
        }

        // merge-patch+json re-hydrates the unsent fields from the current task, so a parts-only PATCH
        // arrives with the existing status (and vice-versa). Only act on a field that actually changed:
        // touch the lifecycle solely when the status differs, and merge parts in (mergeParts dedups by
        // ref, so re-sending the hydrated list is a no-op and never clears the shopping list).
        if ($status !== $task->getStatus()) {
            if (PlannedMaintenanceStatus::Open === $status) {
                $task->reopen();
            } else {
                $task->close($status, new \DateTimeImmutable());
            }
        }

        // Edit the task definition. Re-applying a hydrated (unchanged) field is a no-op, so these run
        // unconditionally; a sent null genuinely clears the field (merge-patch semantics).
        $task->setType($data->type);
        $task->setLabel($data->label);
        $task->setTarget(...$this->target($data->target));
        $task->setDueDate(CalendarDate::parseNullable($data->dueDate));

        if ([] !== $data->parts) {
            $task->mergeParts($data->parts);
        }

        $this->em->flush();
        $this->publisher->publishVehicle($task->getVehicle());

        return PlannedTask::fromEntity($task);
    }

    /**
     * @param array{value?: int|null, unit?: string|null}|null $target
     *
     * @return array{0: int|null, 1: UsageUnit|null}
     */
    private function target(?array $target): array
    {
        if (null === $target || null === ($target['value'] ?? null)) {
            return [null, null];
        }

        $unit = null;
        if (isset($target['unit'])) {
            // @mago-expect analysis:redundant-cast deserialized input: the phpdoc shape is the advertised schema, not a runtime guarantee
            $unit = UsageUnit::tryFrom((string) $target['unit']);
            if (null === $unit) {
                throw new UnprocessableEntityHttpException(\sprintf('Unknown target unit "%s"; expected km, mi or h.', $target['unit']));
            }
        }

        return [(int) $target['value'], $unit];
    }
}
