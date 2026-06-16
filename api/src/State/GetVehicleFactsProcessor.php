<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GarageVehicle;
use App\Dto\GetVehicleFactsInput;
use App\Garage\FactView;
use App\Garage\GarageResolver;
use App\Garage\ToolResult;
use App\Garage\VehicleType;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `get_vehicle_facts` tool: resolves a vehicle (shared garage → upstream write-through)
 * and returns its facts with provenance, or a structured not_in_catalog result on a total
 * miss. The miss is a RESULT, never an exception, so it cannot surface as a -32603 error.
 *
 * @implements ProcessorInterface<GetVehicleFactsInput, CallToolResult>
 */
final class GetVehicleFactsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GarageResolver $resolver,
        private readonly FactView $factView,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $type = VehicleType::from($data->vehicleType);
        $vehicle = $this->resolver->resolve($data->make, $data->model, $data->year, $type, $data->carId);

        $identity = [
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'vehicleType' => $data->vehicleType,
        ];

        if (null === $vehicle) {
            return ToolResult::of([
                'status' => 'not_in_catalog',
                'vehicle' => $identity,
                'missing' => $data->keys,
                'how_to_add' => [
                    'tool' => 'add_vehicle_fact',
                    'schema' => $this->addFactSchema(),
                ],
            ]);
        }

        $facts = [];
        foreach ($vehicle->getFacts() as $fact) {
            if ([] !== $data->keys && !\in_array($fact->getKey(), $data->keys, true)) {
                continue;
            }
            $facts[] = $this->factView->toArray($fact);
        }

        return ToolResult::of([
            'status' => 'ok',
            'vehicle' => ['@id' => $this->iriConverter->getIriFromResource(GarageVehicle::fromEntity($vehicle))] + $identity,
            'facts' => $facts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addFactSchema(): array
    {
        return [
            'make' => 'string (required)',
            'model' => 'string (required)',
            'year' => 'int (optional)',
            'vehicleType' => 'string: car|moto|cv|groundscare (optional, default car)',
            'key' => 'string (required), e.g. oil_type, oil_capacity',
            'value' => 'string (required)',
            'unit' => 'string (optional), e.g. liter',
            'source' => 'string: llm|user|web (optional, default llm)',
            'url' => 'string (optional), source URL when web-sourced',
        ];
    }
}
