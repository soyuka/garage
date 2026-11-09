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
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<ListDocumentsInput, CallToolResult>
 */
final readonly class ListDocumentsProcessor implements ProcessorInterface
{
    public function __construct(
        private VehicleDocumentRepository $documents,
        private VehicleDocumentView $view,
        private VehicleIriResolver $iriResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if (null === $data->vehicle) {
            return ToolResult::error('invalid_input', 'Provide the vehicle @id (IRI) from list_vehicles.');
        }

        $vehicle = $this->iriResolver->resolveVehicle($data->vehicle);
        if ($vehicle instanceof CallToolResult) {
            return $vehicle;
        }

        return ToolResult::of([
            'status' => 'ok',
            'documents' => array_map($this->view->toArray(...), $this->documents->findForVehicle($vehicle)),
        ]);
    }
}
