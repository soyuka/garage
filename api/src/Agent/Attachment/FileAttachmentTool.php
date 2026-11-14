<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use App\Agent\Journal\TurnJournal;
use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentRecorder;
use App\Documentation\VehicleDocumentView;
use App\Entity\TurnAttachment;
use App\Garage\ToolError;
use App\Garage\VehicleIriResolver;
use App\Maintenance\MaintenanceAttachmentRecorder;
use App\Repository\MaintenanceRecordRepository;
use App\Security\OwnerContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Uid\Ulid;

/**
 * The agent's `file_attachment` tool: keeps a file the user attached to THIS turn, as a document of a
 * vehicle (manual, technical note, quote, other) or as the bill of a logged visit. Nothing the user
 * attaches is kept otherwise; the temp file is consumed on success and expires after 24 h if never filed.
 *
 * In-app only: a class-level #[AsTool] joins the garage agent's toolbox, whereas the public MCP server
 * is built from ApiResource tools and its clients have no turn attachments.
 */
#[AsTool(
    'file_attachment',
    'Keep a file the user attached to this message, which is otherwise discarded. Use it only when the file is worth keeping, never by default. as="vehicle_document": a manual, technical note, quote or other paper for a vehicle (pass vehicle = its @id, and kind: manual|technical_note|quote|other, optionally title). as="bill": the invoice of an already-logged visit (pass record = the visit @id from log_maintenance or get_maintenance_history). To log a visit from a photographed invoice: call log_maintenance first, then file_attachment as bill with the new record. attachmentId is the id listed with the attachments in the message. A refused call keeps the file for a retry within the turn.',
    'file',
)]
final readonly class FileAttachmentTool
{
    public function __construct(
        private TurnAttachments $attachments,
        private TurnAttachmentScope $scope,
        private OwnerContext $owner,
        private VehicleIriResolver $vehicles,
        private VehicleDocumentRecorder $documents,
        private VehicleDocumentView $documentView,
        private MaintenanceAttachmentRecorder $bills,
        private MaintenanceRecordRepository $records,
        private TurnJournal $journal,
    ) {
    }

    /**
     * @param string $attachmentId the attachment id given in the message
     * @param string $as           where to file it: vehicle_document or bill
     * @param string $vehicle      vehicle @id (for vehicle_document)
     * @param string $kind         manual, technical_note, quote or other (for vehicle_document)
     * @param string $title        optional display title (for vehicle_document); defaults to the file name
     * @param string $record       maintenance record @id (for bill)
     *
     * @return array<string, mixed>
     */
    public function file(
        string $attachmentId,
        #[Schema(enum: ['vehicle_document', 'bill'])]
        string $as,
        string $vehicle = '',
        string $kind = 'other',
        string $title = '',
        string $record = '',
    ): array {
        $owner = $this->owner->require();
        $attachment = $this->scope->has($attachmentId) ? $this->attachments->find($owner, $attachmentId) : null;
        if (null === $attachment) {
            return $this->error('not_found', 'No such attachment in this message (or it expired).');
        }

        $result = match ($as) {
            'vehicle_document' => $this->asDocument($attachment, $vehicle, $kind, $title),
            'bill' => $this->asBill($attachment, $record),
            default => $this->error('invalid_input', 'as must be "vehicle_document" or "bill".'),
        };

        if ('ok' === $result['status']) {
            $this->attachments->consume($attachment);
            $this->journal->recordAttachmentFiled($attachmentId, $as);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function asDocument(TurnAttachment $attachment, string $vehicleIri, string $kind, string $title): array
    {
        if ('' === $vehicleIri) {
            return $this->error('invalid_input', 'vehicle (its @id from list_vehicles) is required to file a vehicle_document.');
        }
        $documentKind = VehicleDocumentKind::tryFrom($kind);
        if (null === $documentKind) {
            return $this->error('invalid_input', \sprintf('kind must be one of: %s.', implode(', ', array_map(static fn (VehicleDocumentKind $k): string => $k->value, VehicleDocumentKind::cases()))));
        }
        $vehicle = $this->vehicles->resolveVehicle($vehicleIri);
        if ($vehicle instanceof ToolError) {
            return $this->error($vehicle->status, $vehicle->reason);
        }

        $path = $this->tempFile($attachment);
        try {
            $document = $this->documents->record($vehicle, $path, $attachment->getName(), $documentKind, title: '' !== $title ? $title : null);
        } catch (InvalidVehicleDocumentException $e) {
            return $this->error('invalid_input', $e->getMessage());
        } finally {
            unlink($path);
        }

        return ['status' => 'ok', 'document' => $this->documentView->toArray($document)];
    }

    /**
     * @return array<string, mixed>
     */
    private function asBill(TurnAttachment $attachment, string $recordIri): array
    {
        if ('' === $recordIri) {
            return $this->error('invalid_input', 'record (the visit @id) is required to file a bill.');
        }
        $id = basename($recordIri);
        $record = Ulid::isValid($id) ? $this->records->findOwned($id) : null;
        if (null === $record) {
            return $this->error('not_found', \sprintf('No maintenance record at %s.', $recordIri));
        }

        $bill = $this->bills->attach($record, $attachment->getName(), $attachment->getContentType(), $this->attachments->bytes($attachment));

        return ['status' => 'ok', 'attachment' => [
            'id' => $bill->getId(),
            'filename' => $bill->getFilename(),
            'contentType' => $bill->getContentType(),
            'size' => $bill->getSize(),
            'url' => \sprintf('/garage/maintenance-records/%s/attachments/%s', $record->getId(), $bill->getId()),
        ]];
    }

    /** The recorder reads a path (MIME sniff, PDF text extraction), so the stored bytes go to a temp file. */
    private function tempFile(TurnAttachment $attachment): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mpa_turn_');
        file_put_contents($path, $this->attachments->bytes($attachment));

        return $path;
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function error(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
