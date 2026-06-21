<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AgentTurn;
use App\Repository\AgentTurnRepository;
use App\Security\OwnerContext;
use Symfony\Component\Uid\Ulid;

/**
 * Dereferences /agent/turns/{id} into the full journal record — context, ordered tool calls, web
 * searches, token usage and raw payloads. Owner-scoped at the query (findOneForOwner): a turn id
 * belonging to another tenant reads as 404, never a cross-owner disclosure.
 *
 * @implements ProviderInterface<AgentTurn>
 */
final class AgentTurnProvider implements ProviderInterface
{
    public function __construct(
        private readonly AgentTurnRepository $turns,
        private readonly OwnerContext $owner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AgentTurn
    {
        $owner = $this->owner->current();
        if (null === $owner) {
            return null;
        }

        $id = $uriVariables['id'];
        $turn = $this->turns->findOneForOwner($id instanceof Ulid ? $id : Ulid::fromString((string) $id), $owner);

        return null !== $turn ? AgentTurn::fullOf($turn) : null;
    }
}
