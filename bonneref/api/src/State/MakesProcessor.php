<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Make;
use App\Catalog\PartsCatalog;
use App\Dto\MakesInput;
use App\Mcp\CappedResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `list_makes` tool. TecDoc knows ~536 manufacturers, so the result is capped and
 * returned as a plain structured payload — pass `search` to find a brand by name.
 *
 * @implements ProcessorInterface<MakesInput, CallToolResult>
 */
final class MakesProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $makes = $this->catalog->makes(CarTypeResolver::fromString($data->carType), $data->search);

        return CappedResult::of(
            array_map(static fn (Make $make): array => (array) $make, $makes),
            'manufacturers',
            'Narrow with search=<brand name> (e.g. "peugeot", "bmw").',
            $data->offset,
        );
    }
}
