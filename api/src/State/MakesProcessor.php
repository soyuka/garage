<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Make;
use App\Catalog\PartsCatalog;
use App\Dto\MakesInput;

/**
 * MCP `list_makes` tool.
 *
 * @implements ProcessorInterface<MakesInput, list<Make>>
 */
final class MakesProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Make>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->makes(CarTypeResolver::fromString($data->carType));
    }
}
