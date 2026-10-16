<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The `item_id` prefix each retired DBAL cache pool wrote under. The pools are gone from the container, so
 * their namespace is recomputed by Symfony's own CachePoolPass over a throwaway container carrying the
 * same pool id, adapter class and `cache.prefix.seed` — never a reimplementation of its hashing, and never
 * hardcoded hashes, which differ per environment and project dir.
 */
final readonly class LegacyCacheNamespaces
{
    public function __construct(
        #[Autowire(param: 'cache.prefix.seed')]
        private string $seed,
    ) {
    }

    public function of(string $poolId): string
    {
        $container = new ContainerBuilder();
        $container->setParameter('cache.prefix.seed', $this->seed);
        $container->setDefinition($poolId, (new Definition(DoctrineDbalAdapter::class, [null]))->addTag('cache.pool'));

        (new CachePoolPass())->process($container);

        return (string) $container->getDefinition($poolId)->getArgument(0);
    }
}
