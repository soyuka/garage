<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceRecord;
use App\Mercure\DomainPublisher;
use App\Storage\MaintenanceAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * The single write path for a bill on a logged visit, shared by the upload controller and the agent's
 * `file_attachment` tool. The caller has already validated type and size and spent the rate limit.
 */
final readonly class MaintenanceAttachmentRecorder
{
    public function __construct(
        private MaintenanceAttachmentStorage $storage,
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    public function attach(MaintenanceRecord $record, string $filename, string $mime, string $contents): MaintenanceAttachment
    {
        $attachmentId = (string) new Ulid();
        $key = $this->storage->store((string) $record->getId(), $attachmentId, $contents);

        $attachment = new MaintenanceAttachment($attachmentId, $filename, $mime, \strlen($contents), $key);
        $record->addAttachment($attachment);
        $this->em->flush();
        $this->publisher->publishVehicle($record->getVehicle());

        return $attachment;
    }
}
