<?php

declare(strict_types=1);

namespace App\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Exception\InvalidToolInputException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Keeps a constraint violation out of api-platform/mcp's HTTP exception catch, which would answer it
 * as a JSON-RPC -32603; {@see \App\Mcp\DomainErrorHandler} turns it into an `invalid_input` result.
 *
 * @implements ProviderInterface<object>
 */
#[AsDecorator('api_platform.mcp.state_provider')]
final readonly class InvalidInputProvider implements ProviderInterface
{
    /** @param ProviderInterface<object> $decorated */
    public function __construct(
        #[AutowireDecorated] private ProviderInterface $decorated,
    ) {
    }

    // @mago-expect analysis:docblock-type-mismatch the inherited ProviderInterface @return names iterable<T>, which the native object|array|null cannot spell
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        try {
            return $this->decorated->provide($operation, $uriVariables, $context);
        } catch (ValidationException $e) {
            $violations = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $path = $violation->getPropertyPath();
                $violations[] = ('' !== $path ? $path.': ' : '').(string) $violation->getMessage();
            }

            throw new InvalidToolInputException(implode('; ', $violations), previous: $e);
        }
    }
}
