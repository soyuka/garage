<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Bonneref\BonnerefClient;
use App\Dto\NarrowPartsInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<NarrowPartsInput, CallToolResult>
 */
final class NarrowPartsProcessor implements ProcessorInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $narrowed = get_object_vars($this->bonneref->narrowed(
            $data->carId,
            $data->categoryId,
            CarTypeResolver::fromString($data->carType),
            $data->make,
            $data->productGroupId,
        ));
        unset($narrowed['carId']);

        // @mago-expect analysis:less-specific-argument get_object_vars of a final DTO: the shape is sealed, mago leaves it open
        return ToolResult::of(['status' => 'ok'] + $narrowed);
    }
}
