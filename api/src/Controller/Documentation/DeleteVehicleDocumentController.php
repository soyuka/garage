<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Documentation\OwnedVehicleLocator;
use App\Documentation\VehicleDocumentRecorder;
use App\Repository\VehicleDocumentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DeleteVehicleDocumentController
{
    public function __construct(
        private OwnedVehicleLocator $locator,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentRecorder $recorder,
    ) {
    }

    #[Route('/garage/vehicles/{id}/documents/{documentId}', name: 'vehicle_document_delete', methods: ['DELETE'])]
    public function __invoke(string $id, string $documentId): Response
    {
        $vehicle = $this->locator->require($id);
        $document = $this->documents->findOneForVehicle($vehicle, $documentId)
            ?? throw new NotFoundHttpException('No document at this IRI.');

        $this->recorder->remove($document);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
