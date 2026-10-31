<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Catalog\PartsCatalog;
use App\Dto\ArticlesInput;
use App\Mcp\ArticleGroup;
use App\Mcp\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_articles` tool: resolves several part categories in one call and returns
 * the articles grouped per category, so the host never has to fan out one call per
 * filter/category. Categories with no upstream articles are kept as empty groups.
 *
 * @implements ProcessorInterface<ArticlesInput, CallToolResult>
 */
final readonly class ArticlesProcessor implements ProcessorInterface
{
    public function __construct(private PartsCatalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $carType = CarTypeResolver::fromString($data->carType);

        $groups = [];
        foreach ($data->categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;
            $groups[] = ArticleGroup::of(
                $categoryId,
                $this->catalog->articles($data->carId, $categoryId, $carType),
                brands: $data->brands,
            );
        }

        return ToolResult::of([
            'status' => 'ok',
            'groups' => $groups,
        ]);
    }
}
