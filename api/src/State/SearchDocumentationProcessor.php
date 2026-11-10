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
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Result\CallToolResult;

/**
 * Each document hit is also linked as a `resource_link` to its download route.
 *
 * Also plays the search on the Atelier: a `documentScan` frame (on no_match too) on the same channel
 * as the render intents, so both drivers get it without the model spending a call.
 *
 * @implements ProcessorInterface<SearchDocumentationInput, CallToolResult>
 */
final readonly class SearchDocumentationProcessor implements ProcessorInterface
{
    private const int SCAN_HITS = 6;

    public function __construct(
        private DocumentationSearcher $searcher,
        private VehicleIriResolver $iriResolver,
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
        private RenderIntentTools $render,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $query = $data->query;
        if (null === $data->vehicle || null === $query || '' === trim($query)) {
            return ToolResult::error('invalid_input', 'Provide the vehicle @id (IRI) from list_vehicles and a non-empty query.');
        }

        $kind = null;
        if (null !== $data->kind) {
            $kind = VehicleDocumentKind::tryFrom($data->kind);
            if (null === $kind) {
                return ToolResult::error('invalid_input', \sprintf('kind must be one of: %s.', implode(', ', array_map(static fn (VehicleDocumentKind $kind): string => $kind->value, VehicleDocumentKind::cases()))));
            }
        }

        $vehicle = $this->iriResolver->resolveVehicle($data->vehicle);
        if ($vehicle instanceof CallToolResult) {
            return $vehicle;
        }

        $hits = $this->searcher->search($vehicle, $query, $kind, $data->limit);
        $this->render->documentScan(\sprintf('/garage/vehicles/%s', $vehicle->getId()), trim($query), $this->scanHits($vehicle, $hits));
        if ([] === $hits) {
            return ToolResult::of(['status' => 'no_match', 'query' => $query]);
        }

        $result = ToolResult::of(['status' => 'ok', 'query' => $query, 'hits' => $hits]);

        return new CallToolResult([...$result->content, ...$this->links($vehicle, $hits)], false, $result->structuredContent);
    }

    /**
     * @param list<array{documentId: string, ...}> $hits
     *
     * @return list<ResourceLink>
     */
    private function links(Vehicle $vehicle, array $hits): array
    {
        $links = [];
        foreach (array_unique(array_column($hits, 'documentId')) as $id) {
            $document = $this->documents->findOneForVehicle($vehicle, $id);
            if (null !== $document) {
                $links[] = $this->view->resourceLink($document);
            }
        }

        return $links;
    }

    /**
     * @param list<array{documentId: string, page: int, pageLabel: ?string, snippet: string, sourceRef: ?string, ...}> $hits
     *
     * @return list<array{documentId: string, page: int, pageLabel: ?string, url: string, title: string, contentType: string, filename: string, pagesUrl: string, snippet: string, sourceRef: ?string}>
     */
    private function scanHits(Vehicle $vehicle, array $hits): array
    {
        $documents = [];
        $out = [];
        foreach (\array_slice($hits, 0, self::SCAN_HITS) as $hit) {
            $id = $hit['documentId'];
            $documents[$id] ??= $this->documents->findOneForVehicle($vehicle, $id);
            $document = $documents[$id];
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
                'snippet' => $hit['snippet'],
                'sourceRef' => $hit['sourceRef'],
            ];
        }

        return $out;
    }
}
