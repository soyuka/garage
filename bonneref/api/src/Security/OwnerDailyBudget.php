<?php

declare(strict_types=1);

namespace App\Security;

use App\Agent\Journal\TurnCostEstimator;
use App\Exception\RateLimitExceededException;
use App\Repository\AgentTurnRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Per-owner spend cap over a sliding 24 hours, read from the journal: each turn's estimated cost
 * ({@see TurnCostEstimator}, AGENT_PRICES). A turn is journaled when it settles, so turns still running
 * are not counted yet, and an unpriced model counts as zero.
 */
final readonly class OwnerDailyBudget
{
    private const string WINDOW = '-24 hours';

    public function __construct(
        private AgentTurnRepository $turns,
        private TurnCostEstimator $cost,
        private OwnerContext $owner,
        private ClockInterface $clock,
        #[Autowire('%env(float:AGENT_DAILY_BUDGET_USD)%')]
        private float $limitUsd,
    ) {
    }

    /**
     * @throws RateLimitExceededException with the moment the oldest turn of the window leaves it
     */
    public function assertWithin(): void
    {
        $now = $this->clock->now();
        $usage = $this->turns->usageSince($this->owner->current() ?? '', $now->modify(self::WINDOW));

        $spent = 0.0;
        foreach ($usage as $turn) {
            $spent += $this->cost->estimate($turn['model'], $turn['promptTokens'], $turn['completionTokens'], $turn['cacheReadTokens'], $turn['cacheCreationTokens']) ?? 0.0;
        }

        if ([] !== $usage && $spent >= $this->limitUsd) {
            throw new RateLimitExceededException($usage[0]['startedAt']->modify('+24 hours'));
        }
    }
}
