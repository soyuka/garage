<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Agent\Journal\TurnCostEstimator;
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
final readonly class AgentTurnCollectionProvider implements ProviderInterface
{
    private const int LIMIT = 100;

    public function __construct(
        private AgentTurnRepository $turns,
        private OwnerContext $owner,
        private TurnCostEstimator $cost,
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

        return array_map(
            fn ($turn): AgentTurn => AgentTurn::summaryOf($turn, $this->cost),
            $this->turns->findForOwner($owner, self::LIMIT),
        );
    }
}
