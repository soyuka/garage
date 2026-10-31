<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Documentation\VehicleDocumentView;
use App\Dto\ReadDocumentationPageInput;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Repository\VehicleDocumentRepository;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<ReadDocumentationPageInput, CallToolResult>
 */
final readonly class ReadDocumentationPageProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
        private VehicleIriResolver $iriResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle || '' === trim($data->vehicle) || null === $data->document || '' === trim($data->document) || null === $data->page) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'Provide the vehicle @id (IRI), a documentId from list_documents or search_documentation, and the page number (the `page` of a search_documentation hit).']);
        }

        $resolved = $this->iriResolver->resolve($data->vehicle);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        $vehicle = $this->vehicles->find($resolved);
        if (null === $vehicle) {
            return $this->iriResolver->notFound($data->vehicle);
        }

        $document = $this->documents->findOneForVehicle($vehicle, $data->document);
        if (null === $document) {
            return ToolResult::of(['status' => 'not_found', 'reason' => \sprintf('No document %s on this vehicle. Use a documentId from list_documents or search_documentation.', $data->document)]);
        }

        $pageCount = $document->getPages()->count();
        if (0 === $pageCount) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'This document has no extracted text (a scanned file or an image): use show_document so the operator reads it.']);
        }
        if ($data->page < 1 || $data->page > $pageCount) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => \sprintf('page must be between 1 and %d for this document.', $pageCount)]);
        }

        $page = null;
        foreach ($document->getPages() as $candidate) {
            if ($candidate->getNumber() === $data->page) {
                $page = $candidate;
                break;
            }
        }
        if (null === $page) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => \sprintf('page must be between 1 and %d for this document.', $pageCount)]);
        }

        return ToolResult::of([
            'status' => 'ok',
            'documentId' => $document->getId(),
            'title' => $document->getTitle(),
            'kind' => $document->getKind()->value,
            'sourceRef' => $document->getSourceRef(),
            'page' => $page->getNumber(),
            'pageLabel' => $page->getLabel(),
            'pageCount' => $pageCount,
            'text' => $page->getText(),
            'url' => $this->view->url($document),
        ]);
    }
}
