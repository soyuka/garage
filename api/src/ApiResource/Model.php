<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\ModelsInput;
use App\State\ModelCollectionProvider;
use App\PartsApi\PartsApiDate;
use App\State\ModelsProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A vehicle model line of a manufacturer (TecDoc step 2).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/makes/{makeId}/models',
            uriVariables: ['makeId' => new Link(fromClass: Make::class, identifiers: ['id'])],
            provider: ModelCollectionProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type: PC (default) or CV',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
            ],
        ),
    ],
    mcp: [
        'list_models' => new McpToolCollection(
            title: 'List Models',
            description: 'List the model lines of a manufacturer (use list_makes first to get the makeId). Results are capped: pass `search` with the model name without the make (e.g. "6", "CX-5") instead of paging through offsets.',
            input: ModelsInput::class,
            processor: ModelsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class Model
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc model id (modelId)')]
        public int $id,
        public string $name,
        #[ApiProperty(description: 'First production month, yyyy-mm')]
        public ?string $yearFrom = null,
        #[ApiProperty(description: 'Last production month, yyyy-mm; null while still produced')]
        public ?string $yearTo = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromPartsApi(array $row): self
    {
        return new self(
            (int) ($row['modelId'] ?? 0),
            (string) ($row['modelName'] ?? ''),
            PartsApiDate::yearMonth($row['yearsFrom'] ?? null),
            PartsApiDate::yearMonth($row['yearsTo'] ?? null),
        );
    }
}
