<?php

declare(strict_types=1);

namespace App\Controller\Maintenance;

use App\ApiResource\GarageMaintenanceRecord;
use App\Entity\MaintenanceAttachment;
use App\Maintenance\OwnedRecordLocator;
use App\Mercure\DomainPublisher;
use App\Storage\MaintenanceAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

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
    /** 10 MiB — a scanned multi-page invoice fits comfortably; anything larger is likely a mistake. */
    private const int MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const array ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private OwnedRecordLocator $locator,
        private MaintenanceAttachmentStorage $storage,
        private DocumentManager $em,
        private DomainPublisher $publisher,
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
        if ($size > self::MAX_BYTES) {
            throw new UnprocessableEntityHttpException(\sprintf('File too large (%d bytes); the limit is %d bytes.', $size, self::MAX_BYTES));
        }

        $mime = (string) $file->getMimeType();
        if (!\in_array($mime, self::ALLOWED_TYPES, true)) {
            throw new UnprocessableEntityHttpException(\sprintf('Unsupported file type "%s"; allowed: PDF, JPEG, PNG.', $mime));
        }

        $recordId = (string) $record->getId();
        $attachmentId = (string) new Ulid();
        $key = $this->storage->store($recordId, $attachmentId, (string) file_get_contents($file->getPathname()));

        $attachment = new MaintenanceAttachment($attachmentId, $file->getClientOriginalName(), $mime, $size, $key);
        $record->addAttachment($attachment);
        $this->em->flush();
        $this->publisher->publishVehicle($record->getVehicle());

        return new JsonResponse(GarageMaintenanceRecord::attachmentView($attachment, $recordId), Response::HTTP_CREATED);
    }
}
