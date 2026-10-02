<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Documentation\DocumentationSearcher;
use App\Documentation\VehicleDocumentKind;
use App\Dto\SearchDocumentationInput;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<SearchDocumentationInput, CallToolResult>
 */
final class SearchDocumentationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DocumentationSearcher $searcher,
        private readonly VehicleIriResolver $iriResolver,
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
                return ToolResult::of(['status' => 'invalid_input', 'reason' => sprintf('kind must be one of: %s.', implode(', ', array_column(VehicleDocumentKind::cases(), 'value')))]);
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
        if ([] === $hits) {
            return ToolResult::of(['status' => 'no_match', 'query' => $data->query]);
        }

        return ToolResult::of(['status' => 'ok', 'query' => $data->query, 'hits' => $hits]);
    }
}
