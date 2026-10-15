<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Journal;

use App\Agent\Journal\TurnCostEstimator;
use PHPUnit\Framework\TestCase;

final class TurnCostEstimatorTest extends TestCase
{
    public function testPricesEveryTokenBucketPerMillion(): void
    {
        $estimator = new TurnCostEstimator(inputPerMtok: '2', outputPerMtok: '10', cacheReadPerMtok: '0.5', cacheWritePerMtok: '4');

        self::assertEqualsWithDelta(
            (1_000 * 2 + 500 * 10 + 20_000 * 0.5 + 3_000 * 4) / 1_000_000,
            $estimator->estimate(promptTokens: 1_000, completionTokens: 500, cacheReadTokens: 20_000, cacheCreationTokens: 3_000),
            1e-9,
        );
    }

    public function testNoCostWithoutConfiguredPrices(): void
    {
        $estimator = new TurnCostEstimator(inputPerMtok: '', outputPerMtok: '', cacheReadPerMtok: '', cacheWritePerMtok: '');

        self::assertNull($estimator->estimate(1_000, 500, 0, 0));
    }

    public function testNoCostWithoutUsage(): void
    {
        $estimator = new TurnCostEstimator(inputPerMtok: '2', outputPerMtok: '10', cacheReadPerMtok: '0.5', cacheWritePerMtok: '4');

        self::assertNull($estimator->estimate(null, null, null, null));
    }
}
