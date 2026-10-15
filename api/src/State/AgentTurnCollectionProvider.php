<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Agent\Journal\TurnCostEstimator;
use App\Agent\TurnSurface;
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
        private readonly TurnCostEstimator $cost,
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

        $surface = $operation->getParameters()?->get('surface')?->getValue();
        $surface = \is_string($surface) ? TurnSurface::tryFrom($surface) : null;

        $history = filter_var($operation->getParameters()?->get('history')?->getValue(), \FILTER_VALIDATE_BOOLEAN);

        return array_map(
            fn ($turn): AgentTurn => AgentTurn::summaryOf($turn, $this->cost),
            $this->turns->findForOwner($owner, self::LIMIT, $surface, $history),
        );
    }
}
