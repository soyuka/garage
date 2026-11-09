<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\ArticlesInput;
use App\State\ArticleCollectionProvider;
use App\State\ArticlesProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An aftermarket article reference applicable to a vehicle in a given part category (step 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/cars/{carId}/part-categories/{categoryId}/articles',
            uriVariables: [
                'carId' => new Link(fromClass: Car::class, identifiers: ['id']),
                'categoryId' => new Link(fromClass: PartCategory::class, identifiers: ['id']),
            ],
            provider: ArticleCollectionProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type of the carId: PC (default) or CV',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
            ],
        ),
    ],
    mcp: [
        'list_articles' => new McpToolCollection(
            title: 'List Articles',
            description: 'List aftermarket article references (brand + part number) applicable to a vehicle, grouped by part category. Use carId/carType from lookup_plate and pass one or more categoryIds from list_part_categories — batch related categories (e.g. all filters) in a single call. Unfiltered, only the 6 best-ranked brands of each category come back (a popular filter category carries 126), so if the user NAMES a brand you must pass `brands` — otherwise it is very likely absent from the payload and anything you say about it is a guess. A brand under `missing` does not fit this part: say that plainly. Rank brands with compare_brands, and when the user is choosing a reference, close with propose_actions carrying taskId + the chosen part so one click puts it on the task.',
            input: ArticlesInput::class,
            // returns a grouped {status, groups} CallToolResult, not a flat Article collection —
            // suppress the auto-generated JSON-LD collection output schema (it would mislead hosts).
            structuredContent: false,
            processor: ArticlesProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class Article
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc article id')]
        public int $id,
        #[ApiProperty(description: 'Manufacturer part number, e.g. EK-1522')]
        public string $number,
        public string $brand,
        public string $productGroup,
        public int $productTypeId,
        public int $supplierId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromApi(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            number: (string) ($row['number'] ?? ''),
            brand: (string) ($row['brand'] ?? ''),
            productGroup: (string) ($row['productGroup'] ?? ''),
            productTypeId: (int) ($row['productTypeId'] ?? 0),
            supplierId: (int) ($row['supplierId'] ?? 0),
        );
    }
}
