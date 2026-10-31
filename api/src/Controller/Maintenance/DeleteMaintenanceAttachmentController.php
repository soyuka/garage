<?php

declare(strict_types=1);

namespace App\Controller\Maintenance;

use App\Maintenance\OwnedRecordLocator;
use App\Mercure\DomainPublisher;
use App\Storage\MaintenanceAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DELETE /garage/maintenance-records/{id}/attachments/{attachmentId} — remove one bill from a
 * visit. Owner-gated (firewall ^/garage DELETE + ownership here). Drops the binary from the object
 * store and the metadata from the visit in one unit of work, then fans the vehicle out over Mercure.
 */
final readonly class DeleteMaintenanceAttachmentController
{
    public function __construct(
        private OwnedRecordLocator $locator,
        private MaintenanceAttachmentStorage $storage,
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    #[Route('/garage/maintenance-records/{id}/attachments/{attachmentId}', name: 'maintenance_attachment_delete', methods: ['DELETE'])]
    public function __invoke(string $id, string $attachmentId): Response
    {
        $record = $this->locator->require($id);
        $attachment = $record->findAttachment($attachmentId);
        if (null === $attachment) {
            throw new NotFoundHttpException('No attachment at this IRI.');
        }

        $this->storage->delete($attachment->getStorageKey());
        $record->removeAttachment($attachmentId);
        $this->em->flush();
        $this->publisher->publishVehicle($record->getVehicle());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
