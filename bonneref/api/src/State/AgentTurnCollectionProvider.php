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
use App\Vehicle\VehicleIndex;

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
        private VehicleIndex $vehicles,
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

        $rows = array_map(
            fn ($turn): AgentTurn => AgentTurn::summaryOf($turn, $this->cost),
            $this->turns->findForOwner($owner, self::LIMIT, $surface, $history),
        );

        $keys = [];
        foreach ($rows as $row) {
            $vehicle = $row->vehicle;
            if (null !== $vehicle && null !== $vehicle['carId'] && VehicleIndex::lacksNames($vehicle)) {
                $keys[] = ['carId' => $vehicle['carId'], 'carType' => $vehicle['carType']];
            }
        }
        $known = $this->vehicles->findMany($keys);
        foreach ($rows as $row) {
            $row->vehicle = $this->vehicles->complete($row->vehicle, $known);
        }

        return $rows;
    }
}
