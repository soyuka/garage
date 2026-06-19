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
 * Answers 501 until PARTSAPI_KEY_getCars is configured — see the root TODO.md.
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
            ],
        ),
    ],
    mcp: [
        'list_cars' => new McpToolCollection(
            title: 'List Vehicle Variants',
            description: 'List the modifications (engine/body variants) of a model; each result carries the carId (kType) needed by the parts and fluids tools. Currently answers an error until the getCars API token is configured.',
            input: CarsInput::class,
            processor: CarsProcessor::class,
            annotations: ['readOnlyHint' => true, 'openWorldHint' => true],
        ),
    ],
)]
final class Car
{
    public function __construct(
        #[ApiProperty(identifier: true, description: 'TecDoc kType (carId) — key for part categories, articles and fill volumes')]
        public int $id,
        public string $name,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * TODO field names unverified — no getCars token yet. Guessed from getMakes's
     * camelCase convention ({makeId, makeName}, verified live). Adjust on first live call.
     */
    public static function fromPartsApi(array $row): self
    {
        return new self((int) ($row['carId'] ?? 0), (string) ($row['carName'] ?? ''));
    }
}
