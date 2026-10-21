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
use App\PartsApi\PartsApiDate;
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
     * @param array<string, mixed> $row
     */
    public static function fromPartsApi(array $row): self
    {
        $capacity = (string) ($row['CAPACITY'] ?? '');

        return new self(
            (int) ($row['CAR_ID'] ?? 0),
            (string) ($row['CAR_NAME'] ?? ''),
            self::text($row['FULL_MODEL_NAME'] ?? null),
            self::text($row['BODY'] ?? null),
            self::text($row['ENGINE_TYPE'] ?? null),
            1 === preg_match('#^(\d+)#', $capacity, $m) ? (int) $m[1] : null,
            isset($row['POWER_KW']) && is_numeric($row['POWER_KW']) ? (int) round((float) $row['POWER_KW']) : null,
            isset($row['POWER_PS']) && is_numeric($row['POWER_PS']) ? (int) round((float) $row['POWER_PS']) : null,
            PartsApiDate::yearMonth($row['YEAR_START'] ?? null),
            PartsApiDate::yearMonth($row['YEAR_END'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
