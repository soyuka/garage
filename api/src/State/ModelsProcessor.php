<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model;
use App\Catalog\PartsCatalog;
use App\Dto\ModelsInput;

/**
 * MCP `list_models` tool.
 *
 * @implements ProcessorInterface<ModelsInput, list<Model>>
 */
final class ModelsProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Model>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->models($data->makeId, CarTypeResolver::fromString($data->carType));
    }
}
