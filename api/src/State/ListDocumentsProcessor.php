<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Documentation\VehicleDocumentView;
use App\Dto\ListDocumentsInput;
use App\Garage\ToolResult;
use App\Garage\VehicleIriResolver;
use App\Repository\VehicleDocumentRepository;
use App\Repository\VehicleRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<ListDocumentsInput, CallToolResult>
 */
final class ListDocumentsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly VehicleDocumentRepository $documents,
        private readonly VehicleDocumentView $view,
        private readonly VehicleIriResolver $iriResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'Provide the vehicle @id (IRI) from list_vehicles.']);
        }

        $resolved = $this->iriResolver->resolve($data->vehicle);
        if ($resolved instanceof CallToolResult) {
            return $resolved;
        }

        $vehicle = $this->vehicles->find($resolved);
        if (null === $vehicle) {
            return $this->iriResolver->notFound($data->vehicle);
        }

        return ToolResult::of([
            'status' => 'ok',
            'documents' => array_map($this->view->toArray(...), $this->documents->findForVehicle($vehicle)),
        ]);
    }
}
