<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\VehicleDocument;
use Mcp\Schema\Content\ResourceLink;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class VehicleDocumentView
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

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

    /**
     * The binary itself, absolute so an MCP client can fetch it with the bearer it already holds.
     */
    public function resourceLink(VehicleDocument $document): ResourceLink
    {
        return new ResourceLink(
            uri: $this->urls->generate('vehicle_document_download', ['id' => $document->getVehicle()->getId(), 'documentId' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            name: $document->getFilename(),
            title: $document->getTitle(),
            mimeType: $document->getContentType(),
            size: $document->getSize(),
        );
    }

    public function pagesUrl(VehicleDocument $document): string
    {
        return \sprintf('/garage/vehicles/%s/documents/%s/pages', $document->getVehicle()->getId(), $document->getId());
    }
}
