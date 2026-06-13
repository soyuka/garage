<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\FillVolume;
use App\Catalog\PartsCatalog;
use App\Dto\FillVolumesInput;

/**
 * MCP `get_fill_volumes` tool.
 *
 * @implements ProcessorInterface<FillVolumesInput, list<FillVolume>>
 */
final class FillVolumesProcessor implements ProcessorInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<FillVolume>
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->fillVolumes($data->carId);
    }
}
