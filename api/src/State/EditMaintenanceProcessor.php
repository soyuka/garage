<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\EditMaintenanceInput;
use App\Garage\ToolResult;
use App\Maintenance\MaintenanceRecorder;
use App\Maintenance\RecordView;
use App\Maintenance\UsageUnit;
use App\Mercure\DomainPublisher;
use App\Repository\MaintenanceRecordRepository;
use App\Security\OwnerContext;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MCP `edit_maintenance` tool: a sparse in-place edit of one logged visit, addressed by its @id.
 * Owner-gated through the record's vehicle (a foreign or soft-deleted vehicle's record is a
 * not_found result, never an error). Only the fields present in the input are changed — the shared
 * {@see MaintenanceRecorder::edit()} applies whatever keys the change set carries. Unlike
 * log_maintenance there is no vehicle dedup (the record already names its vehicle) and the vehicle's
 * own usage reading is never advanced — an edit corrects history.
 *
 * @implements ProcessorInterface<EditMaintenanceInput, CallToolResult>
 */
final readonly class EditMaintenanceProcessor implements ProcessorInterface
{
    public function __construct(
        private MaintenanceRecordRepository $records,
        private MaintenanceRecorder $recorder,
        private IriConverterInterface $iriConverter,
        private OwnerContext $owner,
        private RecordView $recordView,
        private DomainPublisher $publisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->record) {
            return ToolResult::error('invalid_input', 'Provide the record @id (the IRI from get_maintenance_history) in the `record` field.');
        }

        try {
            $recordId = $this->iriConverter->getResourceFromIri($data->record)->id;
            // @phpstan-ignore catch.neverThrown (GarageMaintenanceRecordProvider 404s through getResourceFromIri, whose @throws omits it)
        } catch (ItemNotFoundException|NotFoundHttpException) {
            return ToolResult::error('not_found', \sprintf('No maintenance record at %s.', $data->record));
        } catch (\Throwable) {
            return ToolResult::error('invalid_input', \sprintf('"%s" is not a valid maintenance-record @id. Use an @id from get_maintenance_history.', $data->record));
        }

        $record = $this->records->find($recordId);
        if (null === $record || $record->getVehicle()->getOwner() !== $this->owner->current() || $record->getVehicle()->isDeleted()) {
            return ToolResult::error('not_found', \sprintf('No maintenance record at %s.', $data->record));
        }

        $changes = [];
        if ([] !== $data->items) {
            $changes['items'] = $data->items;
        }
        if (null !== $data->label) {
            $changes['label'] = $data->label;
        }
        if (null !== $data->performedAt) {
            try {
                $changes['performedAt'] = new \DateTimeImmutable($data->performedAt);
            } catch (\Exception) {
                return ToolResult::error('invalid_input', \sprintf('Invalid performedAt date "%s"; expected an ISO date like "2026-06-15".', $data->performedAt));
            }
        }
        if (null !== $data->usageValue) {
            $changes['usageValue'] = $data->usageValue;
        }
        if (null !== $data->usageUnit) {
            $unit = UsageUnit::tryFrom($data->usageUnit);
            if (null === $unit) {
                return ToolResult::error('invalid_input', \sprintf('Unknown usageUnit "%s"; expected km, mi or hours.', $data->usageUnit));
            }
            $changes['usageUnit'] = $unit;
        }
        if (null !== $data->notes) {
            $changes['notes'] = $data->notes;
        }
        if (null !== $data->performedBy) {
            $changes['performedBy'] = $data->performedBy;
        }
        if (null !== $data->cost) {
            $changes['costCents'] = (int) round(((float) $data->cost) * 100);
            $changes['currency'] = $data->currency ?? 'EUR';
        } elseif (null !== $data->currency) {
            $changes['currency'] = $data->currency;
        }

        $this->recorder->edit($record, $changes);
        $this->publisher->publishVehicle($record->getVehicle());

        return ToolResult::of([
            'status' => 'ok',
            'record' => $this->recordView->toArray($record),
        ]);
    }
}
