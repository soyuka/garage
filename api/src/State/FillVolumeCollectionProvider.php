<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\FillVolume;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<FillVolume>
 */
final class FillVolumeCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<FillVolume>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->fillVolumes((int) $uriVariables['carId']);
    }
}
