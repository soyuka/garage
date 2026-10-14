<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\RemovePlannedPartInput;
use App\Entity\PlannedMaintenance;
use App\Garage\ToolResult;
use App\Maintenance\OwnedPlannedTaskLocator;
use App\Maintenance\PlannedPartRemover;
use App\Maintenance\PlannedTaskView;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Uid\Ulid;

/**
 * MCP `remove_planned_part` tool: drops one shopping-list line from an open planned task, through the
 * same {@see PlannedPartRemover} as the REST DELETE. An unknown, malformed or foreign task/part is a
 * not_found result, never an error.
 *
 * @implements ProcessorInterface<RemovePlannedPartInput, CallToolResult>
 */
final class RemovePlannedPartProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly OwnedPlannedTaskLocator $locator,
        private readonly PlannedPartRemover $remover,
        private readonly IriConverterInterface $iriConverter,
        private readonly PlannedTaskView $taskView,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $task = $this->locateTask($data->task);
        if (null === $task || !Ulid::isValid($data->part) || !$this->remover->remove($task, Ulid::fromString($data->part))) {
            return ToolResult::of([
                'status' => 'not_found',
                'reason' => sprintf('No part "%s" on the planned task %s — read both ids from list_planned_maintenance.', $data->part, $data->task),
            ]);
        }

        return ToolResult::of([
            'status' => 'ok',
            'task' => $this->taskView->toArray($task),
        ]);
    }

    private function locateTask(string $iri): ?PlannedMaintenance
    {
        try {
            $id = $this->iriConverter->getResourceFromIri($iri)->id;
        } catch (\Throwable) {
            return null;
        }

        return $id instanceof Ulid ? $this->locator->find($id) : null;
    }
}
