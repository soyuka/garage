<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\VehicleDocument;

final readonly class VehicleDocumentView
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(VehicleDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'kind' => $document->getKind()->value,
            'title' => $document->getTitle(),
            'source' => $document->getSource()->value,
            'sourceRef' => $document->getSourceRef(),
            'plannedTaskId' => $document->getPlannedTaskId(),
            'filename' => $document->getFilename(),
            'contentType' => $document->getContentType(),
            'size' => $document->getSize(),
            'pageCount' => $document->getPages()->count(),
            'addedAt' => $document->getAddedAt()->format(\DATE_ATOM),
            'url' => $this->url($document),
            'pagesUrl' => $this->pagesUrl($document),
        ];
    }

    public function url(VehicleDocument $document): string
    {
        return \sprintf('/garage/vehicles/%s/documents/%s/file', $document->getVehicle()->getId(), $document->getId());
    }

    public function pagesUrl(VehicleDocument $document): string
    {
        return \sprintf('/garage/vehicles/%s/documents/%s/pages', $document->getVehicle()->getId(), $document->getId());
    }
}
