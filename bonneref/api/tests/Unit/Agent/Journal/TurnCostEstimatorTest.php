<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Journal;

use App\Agent\Journal\TurnCostEstimator;
use PHPUnit\Framework\TestCase;

final class TurnCostEstimatorTest extends TestCase
{
    private const array PRICES = [
        'claude-sonnet-5-5' => ['input' => 2, 'output' => 10, 'cache_read' => 0.5, 'cache_write' => 4],
        'cheap-model' => ['input' => 0.1, 'output' => 0.5, 'cache_read' => 0.01, 'cache_write' => 0.125],
    ];

    public function testPricesEveryTokenBucketPerMillionWithTheTurnModel(): void
    {
        $estimator = new TurnCostEstimator(self::PRICES);

        self::assertEqualsWithDelta(
            (1_000 * 2 + 500 * 10 + 20_000 * 0.5 + 3_000 * 4) / 1_000_000,
            $estimator->estimate('claude-sonnet-5-5', promptTokens: 1_000, completionTokens: 500, cacheReadTokens: 20_000, cacheCreationTokens: 3_000),
            1e-9,
        );
        self::assertEqualsWithDelta(
            (1_000 * 0.1 + 500 * 0.5 + 20_000 * 0.01 + 3_000 * 0.125) / 1_000_000,
            $estimator->estimate('cheap-model', promptTokens: 1_000, completionTokens: 500, cacheReadTokens: 20_000, cacheCreationTokens: 3_000),
            1e-9,
        );
    }

    public function testTheRecordedModelOptionsDoNotChangeThePricedId(): void
    {
        $estimator = new TurnCostEstimator(self::PRICES);

        self::assertSame(
            $estimator->estimate('claude-sonnet-5-5', 1_000, 500, 20_000, 3_000),
            $estimator->estimate('claude-sonnet-5-5?max_tokens=4096&thinking[type]=between_tools', 1_000, 500, 20_000, 3_000),
        );
    }

    public function testNoCostForAnUnpricedModel(): void
    {
        self::assertNull((new TurnCostEstimator(self::PRICES))->estimate('claude-haiku-4-5', 1_000, 500, 0, 0));
        self::assertNull((new TurnCostEstimator(self::PRICES))->estimate('', 1_000, 500, 0, 0));
    }

    public function testNoCostWithoutConfiguredPrices(): void
    {
        self::assertNull((new TurnCostEstimator(null))->estimate('claude-sonnet-5-5', 1_000, 500, 0, 0));
    }

    public function testNoCostForAnIncompletePriceEntry(): void
    {
        $estimator = new TurnCostEstimator(['claude-sonnet-5-5' => ['input' => 2, 'output' => 10, 'cache_read' => 0.2]]);

        self::assertNull($estimator->estimate('claude-sonnet-5-5', 1_000, 500, 0, 0));
    }

    public function testNoCostWithoutUsage(): void
    {
        self::assertNull((new TurnCostEstimator(self::PRICES))->estimate('claude-sonnet-5-5', null, null, null, null));
    }

    public function testATurnCostsItsOwnTokensItsSideCallsAndItsWebSearches(): void
    {
        $estimator = new TurnCostEstimator(self::PRICES, webSearchUsd: 0.01);

        self::assertEqualsWithDelta(
            (1_000 * 2) / 1_000_000 + (10_000 * 0.1 + 1_000 * 0.5) / 1_000_000 + 3 * 0.01,
            $estimator->estimateUsage([
                'model' => 'claude-sonnet-5-5?max_tokens=4096',
                'promptTokens' => 1_000, 'completionTokens' => null, 'cacheReadTokens' => null, 'cacheCreationTokens' => null,
                'sideCalls' => [
                    'cheap-model' => ['promptTokens' => 10_000, 'completionTokens' => 1_000, 'cacheReadTokens' => 0, 'cacheCreationTokens' => 0],
                    'unpriced-model' => ['promptTokens' => 99_000_000, 'completionTokens' => 0, 'cacheReadTokens' => 0, 'cacheCreationTokens' => 0],
                ],
                'webSearchRequests' => 3,
            ]),
            1e-9,
        );
    }

    public function testWebSearchesAloneHaveACost(): void
    {
        self::assertEqualsWithDelta(0.02, (new TurnCostEstimator(null, webSearchUsd: 0.01))->estimateUsage([
            'model' => '', 'promptTokens' => null, 'completionTokens' => null, 'cacheReadTokens' => null, 'cacheCreationTokens' => null,
            'sideCalls' => [], 'webSearchRequests' => 2,
        ]), 1e-9);
    }
}
