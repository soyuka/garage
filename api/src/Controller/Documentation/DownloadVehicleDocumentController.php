<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Documentation\OwnedVehicleLocator;
use App\Repository\VehicleDocumentRepository;
use App\Storage\VehicleDocumentStorage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DownloadVehicleDocumentController
{
    public function __construct(
        private OwnedVehicleLocator $locator,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentStorage $storage,
    ) {
    }

    #[Route('/garage/vehicles/{id}/documents/{documentId}/file', name: 'vehicle_document_download', methods: ['GET'])]
    public function __invoke(string $id, string $documentId): StreamedResponse
    {
        $vehicle = $this->locator->require($id);
        $document = $this->documents->findOneForVehicle($vehicle, $documentId)
            ?? throw new NotFoundHttpException('No document at this IRI.');

        $stream = $this->storage->readStream($document->getStorageKey());

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', $document->getContentType());
        $response->headers->set('Content-Length', (string) $document->getSize());
        // The bytes are user-uploaded and served inline on our origin: a sandboxed document cannot run
        // script or reach our cookies even if a PDF or image turns out to be HTML.
        $response->headers->set('Content-Security-Policy', 'sandbox');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getFilename(),
            preg_replace('/[^\x20-\x7e]/', '_', $document->getFilename()) ?? 'document',
        ));

        return $response;
    }
}
