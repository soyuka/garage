<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Documentation\OwnedVehicleLocator;
use App\Entity\VehicleDocumentPage;
use App\Repository\VehicleDocumentRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListVehicleDocumentPagesController
{
    public function __construct(
        private OwnedVehicleLocator $locator,
        private VehicleDocumentRepository $documents,
    ) {
    }

    #[Route('/garage/vehicles/{id}/documents/{documentId}/pages', name: 'vehicle_document_pages', methods: ['GET'])]
    public function __invoke(string $id, string $documentId): JsonResponse
    {
        $vehicle = $this->locator->require($id);
        $document = $this->documents->findOneForVehicle($vehicle, $documentId)
            ?? throw new NotFoundHttpException('No document at this IRI.');

        $pages = array_map(
            static fn (VehicleDocumentPage $page): array => ['number' => $page->getNumber(), 'label' => $page->getLabel()],
            $document->getPages()->toArray(),
        );
        usort($pages, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return new JsonResponse($pages);
    }
}
