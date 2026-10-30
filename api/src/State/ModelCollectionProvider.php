<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Bonneref\BonnerefClient;
use App\ApiResource\Model;

/**
 * @implements ProviderInterface<Model>
 */
final class ModelCollectionProvider implements ProviderInterface
{
    public function __construct(private readonly BonnerefClient $bonneref)
    {
    }

    /**
     * @return list<Model>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $carType = $operation->getParameters()?->get('carType')?->getValue();

        return $this->bonneref->models(
            (int) $uriVariables['makeId'],
            CarTypeResolver::fromString(\is_string($carType) ? $carType : null),
        );
    }
}
