<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Catalog\Narrowing\ArticleNarrower;
use App\Dto\NarrowPartsInput;
use App\Mcp\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<NarrowPartsInput, CallToolResult>
 */
final readonly class NarrowPartsProcessor implements ProcessorInterface
{
    public function __construct(private ArticleNarrower $narrower)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $result = $this->narrower->narrow(
            $data->carId,
            CarTypeResolver::fromString($data->carType),
            $data->categoryId,
            $data->make,
            $data->productGroupId,
        );

        return ToolResult::of(['status' => 'ok', 'categoryId' => $data->categoryId] + $result->toArray());
    }
}
