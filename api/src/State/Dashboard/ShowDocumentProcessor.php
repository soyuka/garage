<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Documentation\VehicleDocumentView;
use App\Dto\Dashboard\ShowDocumentInput;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Repository\VehicleDocumentRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `show_document`: open the Atelier document viewer on one vehicle document, at a page, with
 * the query highlighted.
 *
 * @implements ProcessorInterface<ShowDocumentInput, CallToolResult>
 */
final readonly class ShowDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
        private VehicleIriResolver $iriResolver,
        private RenderIntentTools $render,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle || '' === trim($data->vehicle) || null === $data->document || '' === trim($data->document)) {
            return ToolResult::error('invalid_input', 'Provide the vehicle @id (IRI) and a documentId from list_documents or search_documentation.');
        }

        $vehicle = $this->iriResolver->resolveVehicle($data->vehicle);
        if ($vehicle instanceof CallToolResult) {
            return $vehicle;
        }

        $document = $this->documents->findOneForVehicle($vehicle, $data->document);
        if (null === $document) {
            return ToolResult::error('not_found', \sprintf('No document %s on this vehicle. Use a documentId from list_documents or search_documentation.', $data->document));
        }

        if (null !== $data->page) {
            $pageCount = $document->getPages()->count();
            if (0 === $pageCount) {
                return ToolResult::error('invalid_input', 'This document has no text pages (an image or a scanned file): omit page.');
            }
            if ($data->page < 1 || $data->page > $pageCount) {
                return ToolResult::error('invalid_input', \sprintf('page must be between 1 and %d for this document.', $pageCount));
            }
        }

        $this->render->showDocument(
            $this->view->url($document),
            $document->getTitle(),
            $document->getContentType(),
            $document->getFilename(),
            $data->page,
            $data->highlight,
            $this->view->pagesUrl($document),
        );

        return ToolResult::of([
            'status' => 'rendered',
            'intent' => 'showDocument',
            'document' => ['id' => $document->getId(), 'title' => $document->getTitle()],
            'page' => $data->page ?? 1,
        ]);
    }
}
