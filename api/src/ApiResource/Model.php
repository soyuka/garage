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
use App\State\ModelsProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A vehicle model line of a manufacturer (TecDoc step 2).
 * Answers 501 until PARTSAPI_KEY_getModels is configured — see the root TODO.md.
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
            description: 'List the model lines of a manufacturer (use list_makes first to get the makeId). Currently answers an error until the getModels API token is configured.',
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
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * TODO field names unverified — no getModels token yet. Guessed from getMakes's
     * camelCase convention ({makeId, makeName}, verified live). Adjust on first live call.
     */
    public static function fromPartsApi(array $row): self
    {
        return new self((int) ($row['modelId'] ?? 0), (string) ($row['modelName'] ?? ''));
    }
}
