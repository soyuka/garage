<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * Captures the provider's RAW web-search response for the turn journal. The {@see WebSearchResult} a
 * client returns is the distilled answer + citations; the raw body (Gemini's groundingMetadata,
 * finishReason, safetyRatings, …) is dropped by the bridge's ResultConverter and otherwise lost — yet
 * it is the only thing that explains an EMPTY answer (a MAX_TOKENS cut, a safety/RECITATION block, no
 * grounding). A client hands it here so the operator can recover it from the journal's raw payloads.
 *
 * Implemented by {@see \App\Agent\Journal\TurnJournal}; a no-op when no turn is open.
 */
interface WebSearchTracer
{
    /**
     * @param array<string, mixed> $rawBody the provider's decoded response body
     */
    public function recordWebSearchResponse(string $backend, string $query, array $rawBody): void;
}
