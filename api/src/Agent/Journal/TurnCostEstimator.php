<?php

declare(strict_types=1);

namespace App\Agent\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Estimated USD cost of a turn from its token buckets, priced with the model the turn recorded.
 * Prices come from AGENT_PRICES (JSON, model id => USD per million tokens): an unpriced model or an
 * incomplete entry yields no estimate rather than a guessed figure.
 */
final readonly class TurnCostEstimator
{
    private const array BUCKETS = ['input', 'output', 'cache_read', 'cache_write'];

    /** @var array<string, array{input: float, output: float, cache_read: float, cache_write: float}> */
    private array $prices;

    /**
     * @param array<string, array<string, mixed>>|null $prices
     */
    public function __construct(
        #[Autowire('%env(default::json:AGENT_PRICES)%')]
        ?array $prices = null,
    ) {
        $valid = [];
        foreach ($prices ?? [] as $model => $entry) {
            $parsed = self::entry($entry);
            if (null !== $parsed) {
                $valid[(string) $model] = $parsed;
            }
        }
        $this->prices = $valid;
    }

    public function estimate(string $model, ?int $promptTokens, ?int $completionTokens, ?int $cacheReadTokens, ?int $cacheCreationTokens): ?float
    {
        $price = $this->prices[$model] ?? null;
        if (null === $price) {
            return null;
        }
        if (null === $promptTokens && null === $completionTokens && null === $cacheReadTokens && null === $cacheCreationTokens) {
            return null;
        }

        return (($promptTokens ?? 0) * $price['input']
            + ($completionTokens ?? 0) * $price['output']
            + ($cacheReadTokens ?? 0) * $price['cache_read']
            + ($cacheCreationTokens ?? 0) * $price['cache_write']) / 1_000_000;
    }

    /**
     * @return array{input: float, output: float, cache_read: float, cache_write: float}|null
     */
    private static function entry(mixed $entry): ?array
    {
        if (!\is_array($entry)) {
            return null;
        }
        $parsed = [];
        foreach (self::BUCKETS as $bucket) {
            if (!isset($entry[$bucket]) || !is_numeric($entry[$bucket])) {
                return null;
            }
            $parsed[$bucket] = (float) $entry[$bucket];
        }

        return $parsed;
    }
}
