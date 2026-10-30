<?php

declare(strict_types=1);

namespace App\Mcp\State;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Exception\RecordNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps a write's miss out of api-platform/mcp's HTTP exception catch.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
#[AsDecorator('api_platform.mcp.state_processor')]
final readonly class DomainMissProcessor implements ProcessorInterface
{
    public function __construct(
        #[AutowireDecorated] private ProcessorInterface $decorated,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        try {
            return $this->decorated->process($data, $operation, $uriVariables, $context);
        } catch (NotFoundHttpException|ItemNotFoundException $e) {
            throw new RecordNotFoundException($e->getMessage(), previous: $e);
        }
    }
}
