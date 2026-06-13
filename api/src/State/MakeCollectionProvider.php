<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Make;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Make>
 */
final class MakeCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Make>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->makes(CarTypeResolver::fromString($context['filters']['carType'] ?? null));
    }
}
