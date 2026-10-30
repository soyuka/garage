<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model;
use App\Catalog\CarType;
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
        $carType = CarTypeResolver::fromString($data->carType);
        $models = $this->catalog->models($data->makeId, $carType);
        $search = trim($data->search ?? '');

        if ('' !== $search) {
            $search = $this->stripMakeName($search, $data->makeId, $carType);
            $needle = mb_strtolower($search);
            $models = array_values(array_filter($models, static fn (Model $model): bool => str_contains(mb_strtolower($model->name), $needle)));
            usort($models, fn (Model $a, Model $b): int => $this->rank($a->name, $needle) <=> $this->rank($b->name, $needle));
        }

        return CappedResult::of(
            array_map(static fn (Model $model): array => (array) $model, $models),
            'models',
            'Narrow with search=<model name without the make> (e.g. "6", "CX-5", "Clio") rather than paging.',
            $data->offset,
        );
    }

    private function stripMakeName(string $search, int $makeId, CarType $carType): string
    {
        foreach ($this->catalog->makes($carType) as $make) {
            if ($make->id !== $makeId) {
                continue;
            }

            $prefix = mb_strtolower($make->name);
            $lower = mb_strtolower($search);
            if (str_starts_with($lower, $prefix.' ')) {
                return ltrim(mb_substr($search, mb_strlen($prefix)));
            }

            break;
        }

        return $search;
    }

    /**
     * Exact name or leading token first, then prefix, then any substring, so a short query like "6" is not buried under "626" rows.
     */
    private function rank(string $name, string $needle): int
    {
        $lower = mb_strtolower($name);
        $firstToken = preg_split('/[\s(]/', $lower, 2)[0];

        return match (true) {
            $lower === $needle, $firstToken === $needle => 0,
            str_starts_with($lower, $needle) => 1,
            default => 2,
        };
    }
}
