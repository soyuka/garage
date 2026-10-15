<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model;
use App\Catalog\PartsCatalog;
use App\Dto\ModelsInput;
use App\Mcp\CappedResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_models` tool.
 *
 * @implements ProcessorInterface<ModelsInput, CallToolResult>
 */
final class ModelsProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $models = $this->catalog->models($data->makeId, CarTypeResolver::fromString($data->carType));

        if (null !== $data->search && '' !== $data->search) {
            $models = array_values(array_filter($models, static fn (Model $model): bool => false !== mb_stripos($model->name, $data->search)));
        }

        return CappedResult::of(
            array_map(static fn (Model $model): array => (array) $model, $models),
            'models',
            'Narrow with search=<model name> (e.g. "hatchback").',
            $data->offset,
        );
    }
}
