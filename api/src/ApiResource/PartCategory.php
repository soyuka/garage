<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\PartCategoriesInput;
use App\State\PartCategoriesProcessor;
use App\State\PartCategoryCollectionProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A node of the TecDoc product group tree for a given vehicle modification (step 4).
 * Only nodes applicable to the requested carId are returned; only leaf nodes carry articles.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/cars/{carId}/part-categories',
            uriVariables: ['carId' => new Link(fromClass: Car::class, identifiers: ['id'])],
            provider: PartCategoryCollectionProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type of the carId: PC (default) or CV',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
                'leafOnly' => new QueryParameter(
                    description: 'Return only leaf nodes (the ones carrying articles). Default true.',
                    schema: ['type' => 'boolean', 'default' => true],
                ),
            ],
        ),
    ],
    mcp: [
        'list_part_categories' => new McpToolCollection(
            title: 'List Part Categories',
            description: 'List the part categories (TecDoc product group tree) applicable to a vehicle. Use the carId (kType) and carType from lookup_plate or list_cars. By default only leaf categories are returned — pass their id to list_articles.',
            input: PartCategoriesInput::class,
            processor: PartCategoriesProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class PartCategory
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc group id (strId) — pass to list_articles')]
        public int $id,
        public ?int $parentId,
        public int $level,
        public string $name,
        #[ApiProperty(description: 'Full human-readable path, e.g. "Filters > Air Filter"')]
        public string $path,
        #[ApiProperty(description: 'Leaf nodes are the ones that carry articles')]
        public bool $leaf,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $nodes raw getSearchTree response
     *
     * @return list<self>
     */
    public static function fromPartsApiTree(array $nodes, bool $leafOnly): array
    {
        $parents = [];
        foreach ($nodes as $node) {
            if (null !== ($node['STR_ID_PARENT'] ?? null)) {
                $parents[(int) $node['STR_ID_PARENT']] = true;
            }
        }

        $categories = [];
        foreach ($nodes as $node) {
            $leaf = !isset($parents[(int) $node['STR_ID']]);
            if ($leafOnly && !$leaf) {
                continue;
            }

            $categories[] = new self(
                id: (int) $node['STR_ID'],
                parentId: null !== ($node['STR_ID_PARENT'] ?? null) ? (int) $node['STR_ID_PARENT'] : null,
                level: (int) ($node['STR_LEVEL'] ?? 0),
                name: (string) ($node['STR_NODE_NAME'] ?? ''),
                path: (string) ($node['STR_PATH'] ?? ''),
                leaf: $leaf,
            );
        }

        return $categories;
    }
}
