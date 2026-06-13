<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Model;
use App\Catalog\PartsCatalog;

/**
 * @implements ProviderInterface<Model>
 */
final class ModelCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly PartsCatalog $catalog)
    {
    }

    /**
     * @return list<Model>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->catalog->models(
            (int) $uriVariables['makeId'],
            CarTypeResolver::fromString($context['filters']['carType'] ?? null),
        );
    }
}
