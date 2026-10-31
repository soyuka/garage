<?php

declare(strict_types=1);

namespace App\Controller\Maintenance;

use App\Maintenance\OwnedRecordLocator;
use App\Storage\MaintenanceAttachmentStorage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /garage/maintenance-records/{id}/attachments/{attachmentId} — stream one bill back. Unlike
 * the other garage reads (public for now), a bill is owner-gated: it is a private financial record,
 * so an anonymous request — which {@see OwnedRecordLocator} resolves to a null owner — gets a 404,
 * never the bytes. The dashboard fetches it with its Bearer and shows it from an object URL.
 *
 * Served inline (Content-Disposition: inline) so the browser renders the PDF/image in place; the
 * original filename rides along for a "Save as".
 */
final readonly class DownloadMaintenanceAttachmentController
{
    public function __construct(
        private OwnedRecordLocator $locator,
        private MaintenanceAttachmentStorage $storage,
    ) {
    }

    #[Route('/garage/maintenance-records/{id}/attachments/{attachmentId}', name: 'maintenance_attachment_download', methods: ['GET'])]
    public function __invoke(string $id, string $attachmentId): StreamedResponse
    {
        $record = $this->locator->require($id);
        $attachment = $record->findAttachment($attachmentId);
        if (null === $attachment) {
            throw new NotFoundHttpException('No attachment at this IRI.');
        }

        $stream = $this->storage->readStream($attachment->getStorageKey());

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', $attachment->getContentType());
        $response->headers->set('Content-Length', (string) $attachment->getSize());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $attachment->getFilename(),
            // ASCII fallback for the rare client that ignores the UTF-8 filename* parameter.
            preg_replace('/[^\x20-\x7e]/', '_', $attachment->getFilename()) ?? 'bill',
        ));

        return $response;
    }
}
