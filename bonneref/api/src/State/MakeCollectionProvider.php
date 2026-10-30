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
        $parameters = $operation->getParameters();
        $carType = $parameters?->get('carType')?->getValue();
        $search = $parameters?->get('search')?->getValue();

        return $this->catalog->makes(
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
            \is_string($search) ? $search : null,
        );
    }
}
