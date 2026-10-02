<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Documentation\OwnedVehicleLocator;
use App\Documentation\VehicleDocumentView;
use App\Repository\VehicleDocumentRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListVehicleDocumentsController
{
    public function __construct(
        private OwnedVehicleLocator $locator,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
    ) {
    }

    #[Route('/garage/vehicles/{id}/documents', name: 'vehicle_document_list', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $vehicle = $this->locator->require($id);

        return new JsonResponse(array_map($this->view->toArray(...), $this->documents->findForVehicle($vehicle)));
    }
}
