<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AgentTurn;
use App\Repository\AgentTurnRepository;
use App\Security\OwnerContext;

/**
 * Lists the current owner's agent turns, newest first — the dashboard journal drawer reads it. Scoped
 * to the authenticated tenant: a turn belongs to whoever ran it, so an owner never sees another's
 * runs. An owner with no turns yields an empty collection.
 *
 * @implements ProviderInterface<AgentTurn>
 */
final class AgentTurnCollectionProvider implements ProviderInterface
{
    private const int LIMIT = 100;

    public function __construct(
        private readonly AgentTurnRepository $turns,
        private readonly OwnerContext $owner,
    ) {
    }

    /**
     * @return list<AgentTurn>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $owner = $this->owner->current();
        if (null === $owner) {
            return [];
        }

        return array_map(AgentTurn::summaryOf(...), $this->turns->findForOwner($owner, self::LIMIT));
    }
}
