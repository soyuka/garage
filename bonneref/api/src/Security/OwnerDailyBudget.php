<?php

declare(strict_types=1);

namespace App\Security;

use App\Agent\Journal\TurnCostEstimator;
use App\Exception\RateLimitExceededException;
use App\Repository\AgentTurnRepository;
use App\Repository\OwnerSpendRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Per-owner spend cap over a sliding 24 hours: each journaled turn's estimated cost ({@see TurnCostEstimator},
 * AGENT_PRICES), its web-search side calls and searches included, plus the vision intakes ({@see SpendRecorder}).
 * A turn is journaled when it settles, so turns still running are not counted yet, and an unpriced model
 * counts as zero.
 */
final readonly class OwnerDailyBudget
{
    private const string WINDOW = '-24 hours';

    public function __construct(
        private AgentTurnRepository $turns,
        private OwnerSpendRepository $intakes,
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
        $owner = $this->owner->current() ?? '';
        $since = $this->clock->now()->modify(self::WINDOW);

        $spent = 0.0;
        $oldest = null;
        foreach ($this->turns->usageSince($owner, $since) as $turn) {
            $spent += $this->cost->estimateUsage($turn) ?? 0.0;
            $oldest = min($oldest ?? $turn['startedAt'], $turn['startedAt']);
        }
        foreach ($this->intakes->since($owner, $since) as $intake) {
            $spent += $this->cost->estimate($intake->model, $intake->promptTokens, $intake->completionTokens, $intake->cacheReadTokens, $intake->cacheCreationTokens) ?? 0.0;
            $oldest = min($oldest ?? $intake->at, $intake->at);
        }

        if (null !== $oldest && $spent >= $this->limitUsd) {
            throw new RateLimitExceededException($oldest->modify('+24 hours'));
        }
    }
}
