<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\Vehicle;
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
        return $this->urlFor($document->getVehicle(), $document->getId());
    }

    public function urlFor(Vehicle $vehicle, string $documentId): string
    {
        return \sprintf('/garage/vehicles/%s/documents/%s/file', $vehicle->getId(), $documentId);
    }

    /**
     * The binary itself, absolute so an MCP client can fetch it with the bearer it already holds.
     */
    public function resourceLink(VehicleDocument $document): ResourceLink
    {
        return $this->resourceLinkFor($document->getVehicle(), new DocumentMeta(
            $document->getId(),
            $document->getTitle(),
            $document->getFilename(),
            $document->getContentType(),
            $document->getSize(),
        ));
    }

    public function resourceLinkFor(Vehicle $vehicle, DocumentMeta $meta): ResourceLink
    {
        return new ResourceLink(
            uri: $this->urls->generate('vehicle_document_download', ['id' => $vehicle->getId(), 'documentId' => $meta->id], UrlGeneratorInterface::ABSOLUTE_URL),
            name: $meta->filename,
            title: $meta->title,
            mimeType: $meta->contentType,
            size: $meta->size,
        );
    }

    public function pagesUrl(VehicleDocument $document): string
    {
        return $this->pagesUrlFor($document->getVehicle(), $document->getId());
    }

    public function pagesUrlFor(Vehicle $vehicle, string $documentId): string
    {
        return \sprintf('/garage/vehicles/%s/documents/%s/pages', $vehicle->getId(), $documentId);
    }
}
