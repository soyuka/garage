<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Documentation\DocumentationSearcher;
use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentView;
use App\Dto\SearchDocumentationInput;
use App\Entity\Vehicle;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Repository\VehicleDocumentRepository;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * Also plays the search on the Atelier: a `documentScan` frame (on no_match too) on the same channel
 * as the render intents, so both drivers get it without the model spending a call.
 *
 * @implements ProcessorInterface<SearchDocumentationInput, CallToolResult>
 */
final readonly class SearchDocumentationProcessor implements ProcessorInterface
{
    private const int SCAN_HITS = 6;

    public function __construct(
        private VehicleRepository $vehicles,
        private DocumentationSearcher $searcher,
        private VehicleIriResolver $iriResolver,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
        private RenderIntentTools $render,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle || null === $data->query || '' === trim($data->query)) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'Provide the vehicle @id (IRI) from list_vehicles and a non-empty query.']);
        }

        $kind = null;
        if (null !== $data->kind) {
            $kind = VehicleDocumentKind::tryFrom($data->kind);
            if (null === $kind) {
                return ToolResult::of(['status' => 'invalid_input', 'reason' => \sprintf('kind must be one of: %s.', implode(', ', array_column(VehicleDocumentKind::cases(), 'value')))]);
            }
        }

        $resolved = $this->iriResolver->resolve($data->vehicle);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        $vehicle = $this->vehicles->find($resolved);
        if (null === $vehicle) {
            return $this->iriResolver->notFound($data->vehicle);
        }

        $hits = $this->searcher->search($vehicle, $data->query, $kind, $data->limit);
        $this->render->documentScan(\sprintf('/garage/vehicles/%s', $vehicle->getId()), trim($data->query), $this->scanHits($vehicle, $hits));
        if ([] === $hits) {
            return ToolResult::of(['status' => 'no_match', 'query' => $data->query]);
        }

        return ToolResult::of(['status' => 'ok', 'query' => $data->query, 'hits' => $hits]);
    }

    /**
     * @param list<array{documentId: string, page: int, pageLabel: ?string}> $hits
     *
     * @return list<array{documentId: string, page: int, pageLabel: ?string, url: string, title: string, contentType: string, filename: string, pagesUrl: string}>
     */
    private function scanHits(Vehicle $vehicle, array $hits): array
    {
        $documents = [];
        $out = [];
        foreach (\array_slice($hits, 0, self::SCAN_HITS) as $hit) {
            $id = $hit['documentId'];
            $document = $documents[$id] ??= $this->documents->findOneForVehicle($vehicle, $id);
            if (null === $document) {
                continue;
            }
            $out[] = [
                'documentId' => $id,
                'page' => $hit['page'],
                'pageLabel' => $hit['pageLabel'],
                'url' => $this->view->url($document),
                'title' => $document->getTitle(),
                'contentType' => $document->getContentType(),
                'filename' => $document->getFilename(),
                'pagesUrl' => $this->view->pagesUrl($document),
            ];
        }

        return $out;
    }
}
