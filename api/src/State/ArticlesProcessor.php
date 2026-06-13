<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Article;
use App\Catalog\PartsCatalog;
use App\Dto\ArticlesInput;

/**
 * MCP `list_articles` tool.
 *
 * @implements ProcessorInterface<ArticlesInput, list<Article>>
 */
final class ArticlesProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Article>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->articles($data->carId, $data->categoryId, CarTypeResolver::fromString($data->carType));
    }
}
