<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Car;
use App\Catalog\PartsCatalog;
use App\Dto\CarsInput;

/**
 * MCP `list_cars` tool.
 *
 * @implements ProcessorInterface<CarsInput, list<Car>>
 */
final class CarsProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Car>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->cars($data->makeId, $data->modelId, CarTypeResolver::fromString($data->carType));
    }
}
