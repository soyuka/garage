<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Dto\CarsInput;
use App\State\CarCollectionProvider;
use App\State\CarsProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A TecDoc vehicle modification (step 3). Its id is the kType used by all parts/fluids
 * endpoints. Plate lookups resolve to a Car id directly — this browse route is the
 * alternative entry when no plate is available.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/makes/{makeId}/models/{modelId}/cars',
            uriVariables: [
                'makeId' => new Link(fromClass: Make::class, identifiers: ['id']),
                'modelId' => new Link(fromClass: Model::class, identifiers: ['id']),
            ],
            provider: CarCollectionProvider::class,
            parameters: [
                'carType' => new QueryParameter(
                    description: 'TecDoc vehicle type: PC (default) or CV',
                    schema: ['type' => 'string', 'enum' => ['PC', 'CV'], 'default' => 'PC'],
                    constraints: [new Assert\Choice(choices: ['PC', 'CV'])],
                ),
                'year' => new QueryParameter(
                    description: 'Keep versions produced in this year (open-ended versions are still produced)',
                    schema: ['type' => 'integer'],
                    constraints: [new Assert\Type('numeric')],
                ),
                'capacityLitres' => new QueryParameter(
                    description: 'Engine displacement in litres, e.g. 2.0 (+-0.05)',
                    schema: ['type' => 'number'],
                    constraints: [new Assert\Type('numeric')],
                ),
                'fuel' => new QueryParameter(
                    description: 'essence, diesel, hybride, électrique or GPL',
                    schema: ['type' => 'string', 'enum' => ['essence', 'diesel', 'hybride', 'électrique', 'GPL']],
                ),
                'search' => new QueryParameter(
                    description: 'Case-insensitive substring on the version name or full model name',
                    schema: ['type' => 'string'],
                ),
            ],
        ),
    ],
    mcp: [
        'list_cars' => new McpToolCollection(
            title: 'List Vehicle Variants',
            description: 'List the modifications (engine/body variants) of a model; each result carries the carId (kType) needed by the parts tools. Returns at most 30 rows plus total and truncated: when truncated is true the list is INCOMPLETE and cannot be paged, so narrow it with the server-side filters year, capacityLitres, fuel or search (always pass the ones the user gave) and never treat it as complete.',
            input: CarsInput::class,
            // returns a capped {totalItems, items, …} CallToolResult, not a Hydra collection.
            structuredContent: false,
            processor: CarsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class Car
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc kType (carId) — key for part categories and articles')]
        public int $id,
        public string $name,
        public ?string $fullName = null,
        public ?string $body = null,
        public ?string $engineType = null,
        #[ApiProperty(description: 'Engine displacement in cm3')]
        public ?int $capacityCc = null,
        public ?int $powerKw = null,
        public ?int $powerPs = null,
        #[ApiProperty(description: 'First production month, yyyy-mm')]
        public ?string $yearFrom = null,
        #[ApiProperty(description: 'Last production month, yyyy-mm; null while still produced')]
        public ?string $yearTo = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromApi(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            self::text($row['fullName'] ?? null),
            self::text($row['body'] ?? null),
            self::text($row['engineType'] ?? null),
            isset($row['capacityCc']) ? (int) $row['capacityCc'] : null,
            isset($row['powerKw']) ? (int) $row['powerKw'] : null,
            isset($row['powerPs']) ? (int) $row['powerPs'] : null,
            self::text($row['yearFrom'] ?? null),
            self::text($row['yearTo'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
