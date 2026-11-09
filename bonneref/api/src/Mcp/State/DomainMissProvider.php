<?php

declare(strict_types=1);

namespace App\Mcp\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Exception\RecordNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps a read's miss out of api-platform/mcp's HTTP exception catch.
 *
 * @implements ProviderInterface<object>
 */
#[AsDecorator('api_platform.mcp.state_provider')]
final readonly class DomainMissProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<object> $decorated
     */
    public function __construct(
        #[AutowireDecorated] private ProviderInterface $decorated,
    ) {
    }

    // @mago-expect analysis:docblock-type-mismatch the inherited ProviderInterface @return names iterable<T>, which the native object|array|null cannot spell
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        try {
            return $this->decorated->provide($operation, $uriVariables, $context);
        } catch (NotFoundHttpException|ItemNotFoundException $e) {
            throw new RecordNotFoundException($e->getMessage(), previous: $e);
        }
    }
}
