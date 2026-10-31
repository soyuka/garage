<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Car;
use App\Catalog\CarFilter;
use App\Catalog\PartsCatalog;
use App\Dto\CarsInput;
use App\Mcp\CappedResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_cars` tool.
 *
 * @implements ProcessorInterface<CarsInput, CallToolResult>
 */
final readonly class CarsProcessor implements ProcessorInterface
{
    private const LIMIT = 30;

    public function __construct(private PartsCatalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $cars = $this->catalog->cars($data->makeId, $data->modelId, CarTypeResolver::fromString($data->carType));

        $cars = CarFilter::apply($cars, $data->year, $data->capacityLitres, $data->fuel, $data->search);

        return CappedResult::of(
            array_map(static fn (Car $car): array => (array) $car, $cars),
            'vehicle variants',
            'Narrow with year, capacityLitres, fuel or search; never treat this list as complete.',
            limit: self::LIMIT,
            pageable: false,
        );
    }
}
