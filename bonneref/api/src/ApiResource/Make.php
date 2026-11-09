<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\MakesInput;
use App\State\MakeCollectionProvider;
use App\State\MakesProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A vehicle manufacturer (TecDoc step 1).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            // One default page still holds the largest collection the catalogue has (measured 2026-11-09: 509 makes,
            // 704 categories of a car, 1685 articles of one category), so a caller reading only the first page is whole.
            paginationItemsPerPage: 600,
            paginationMaximumItemsPerPage: 600,
            paginationClientItemsPerPage: true,
            uriTemplate: '/makes',
            provider: MakeCollectionProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type: PC (passenger car, default) or CV (commercial vehicle)',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
                'search' => new QueryParameter(
                    description: 'Case-insensitive substring filter on the manufacturer name, e.g. "peugeot".',
                    schema: ['type' => 'string', 'maxLength' => 64],
                ),
            ],
        ),
    ],
    mcp: [
        'list_makes' => new McpToolCollection(
            title: 'List Manufacturers',
            description: 'List vehicle manufacturers (brands) known to the TecDoc catalog. Entry point for browsing by brand/model instead of by plate. TecDoc lists ~536 manufacturers: results are capped, so pass search=<brand name> to find one.',
            input: MakesInput::class,
            validate: true,
            // returns a capped {totalItems, items, …} CallToolResult, not a Hydra collection.
            structuredContent: false,
            processor: MakesProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => false],
        ),
    ],
)]
final class Make
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc manufacturer id (manuId)')]
        public int $id,
        public string $name,
    ) {
    }

    /**
     * @param array{makeId: int|string, makeName: string} $row verified live 2026-06-12
     */
    public static function fromCatalogRow(array $row): self
    {
        return new self((int) $row['makeId'], (string) $row['makeName']);
    }
}
