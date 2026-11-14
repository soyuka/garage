<?php

declare(strict_types=1);

namespace App\Controller\Maintenance;

use App\ApiResource\GarageMaintenanceRecord;
use App\Maintenance\MaintenanceAttachmentRecorder;
use App\Maintenance\OwnedRecordLocator;
use App\Security\OwnerRateLimit;
use App\Storage\AcceptedUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /garage/maintenance-records/{id}/attachments — attach one bill (PDF/JPEG/PNG) to a logged
 * visit. Multipart, field "file". The write is gated at the firewall (^/garage POST) and again on
 * ownership here. The MIME is sniffed from the bytes, never trusted from the client header, so a
 * .pdf-named executable is rejected. Stores the binary in the object store and records the metadata
 * on the visit; a visit can carry several bills, so this appends rather than replaces. Fans the
 * vehicle out over Mercure like any write so the dashboard refreshes.
 */
final readonly class UploadMaintenanceAttachmentController
{
    public function __construct(
        private OwnedRecordLocator $locator,
        private MaintenanceAttachmentRecorder $recorder,
        private OwnerRateLimit $rateLimit,
        private RateLimiterFactoryInterface $uploadLimiter,
    ) {
    }

    #[Route('/garage/maintenance-records/{id}/attachments', name: 'maintenance_attachment_upload', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $record = $this->locator->require($id);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new UnprocessableEntityHttpException('A file is required in the multipart "file" field.');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException(\sprintf('Upload failed: %s.', $file->getErrorMessage()));
        }

        $size = (int) $file->getSize();
        if ($size > AcceptedUpload::MAX_BYTES) {
            throw new UnprocessableEntityHttpException(\sprintf('File too large (%d bytes); the limit is %d bytes.', $size, AcceptedUpload::MAX_BYTES));
        }

        $mime = AcceptedUpload::sniff($file->getPathname());
        if (null === $mime) {
            throw new UnprocessableEntityHttpException(\sprintf('Unsupported file type "%s"; allowed: PDF, JPEG, PNG.', (string) $file->getMimeType()));
        }

        $this->rateLimit->consume($this->uploadLimiter, 'Upload');

        $attachment = $this->recorder->attach($record, $file->getClientOriginalName(), $mime, (string) file_get_contents($file->getPathname()));

        return new JsonResponse(GarageMaintenanceRecord::attachmentView($attachment, (string) $record->getId()), Response::HTTP_CREATED);
    }
}
