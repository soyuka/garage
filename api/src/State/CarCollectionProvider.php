<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Car;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Car>
 */
final class CarCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Car>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->cars(
            (int) $uriVariables['makeId'],
            (int) $uriVariables['modelId'],
            CarTypeResolver::fromString($context['filters']['carType'] ?? null),
        );
    }
}
