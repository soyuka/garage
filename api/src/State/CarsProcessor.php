<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Bonneref\BonnerefClient;
use App\ApiResource\Car;
use App\Dto\CarsInput;
use App\Mcp\CappedResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_cars` tool.
 *
 * @implements ProcessorInterface<CarsInput, CallToolResult>
 */
final class CarsProcessor implements ProcessorInterface
{
    private const LIMIT = 30;

    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $cars = $this->bonneref->cars($data->makeId, $data->modelId, CarTypeResolver::fromString($data->carType), $data->year, $data->capacityLitres, $data->fuel, $data->search);

        return CappedResult::of(
            array_map(static fn (Car $car): array => (array) $car, $cars),
            'vehicle variants',
            'Narrow with year, capacityLitres, fuel or search; never treat this list as complete.',
            limit: self::LIMIT,
            pageable: false,
        );
    }
}
