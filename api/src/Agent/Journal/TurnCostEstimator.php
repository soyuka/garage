<?php

declare(strict_types=1);

namespace App\Agent\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Estimated USD cost of a turn from its token buckets. Prices come from the environment
 * (AGENT_PRICE_*_PER_MTOK, USD per million tokens): an unset price yields no estimate rather than a
 * guessed figure.
 */
final readonly class TurnCostEstimator
{
    private ?float $input;
    private ?float $output;
    private ?float $cacheRead;
    private ?float $cacheWrite;

    public function __construct(
        #[Autowire('%env(default::AGENT_PRICE_INPUT_PER_MTOK)%')]
        ?string $inputPerMtok = null,
        #[Autowire('%env(default::AGENT_PRICE_OUTPUT_PER_MTOK)%')]
        ?string $outputPerMtok = null,
        #[Autowire('%env(default::AGENT_PRICE_CACHE_READ_PER_MTOK)%')]
        ?string $cacheReadPerMtok = null,
        #[Autowire('%env(default::AGENT_PRICE_CACHE_WRITE_PER_MTOK)%')]
        ?string $cacheWritePerMtok = null,
    ) {
        $this->input = self::price($inputPerMtok);
        $this->output = self::price($outputPerMtok);
        $this->cacheRead = self::price($cacheReadPerMtok);
        $this->cacheWrite = self::price($cacheWritePerMtok);
    }

    public function estimate(?int $promptTokens, ?int $completionTokens, ?int $cacheReadTokens, ?int $cacheCreationTokens): ?float
    {
        if (null === $this->input || null === $this->output || null === $this->cacheRead || null === $this->cacheWrite) {
            return null;
        }
        if (null === $promptTokens && null === $completionTokens && null === $cacheReadTokens && null === $cacheCreationTokens) {
            return null;
        }

        return (($promptTokens ?? 0) * $this->input
            + ($completionTokens ?? 0) * $this->output
            + ($cacheReadTokens ?? 0) * $this->cacheRead
            + ($cacheCreationTokens ?? 0) * $this->cacheWrite) / 1_000_000;
    }

    private static function price(?string $raw): ?float
    {
        return null === $raw || !is_numeric(trim($raw)) ? null : (float) trim($raw);
    }
}
