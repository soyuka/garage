<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

use App\Agent\WebSearchBudget;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The garage agent's `web_search` tool. The orchestrator calls it like any other tool; the actual
 * search runs out-of-band through a {@see WebSearchClient} (Haiku today), so the main loop carries no
 * provider-native web search. This is the model-agnostic replacement for the per-platform mechanism in
 * {@see \App\Agent\WebSearchInputProcessor} — set AGENT_WEB_SEARCH=off so the two don't both declare a
 * `web_search` (Anthropic rejects duplicate tool names).
 *
 * Per-turn cap: web search is the costliest move (each cited result is re-sent every subsequent loop
 * pass), so a search is allowed only while the turn's {@see WebSearchBudget} has room (default 2) —
 * reset per turn by {@see \App\MessageHandler\RunAgentTurnHandler}. Once spent, the tool returns a plain
 * instruction to answer from what's already known instead of searching again.
 *
 * An empty grounded answer is NOT passed through as a blank string (that left the orchestrator with no
 * signal and a noop turn): it returns an explicit "no result" message and lets the model decide whether
 * to spend its remaining budget on another query or fall back to known facts. The empty attempt still
 * spends one unit, so the two-search cap bounds an unproductive query loop.
 *
 * Class-level #[AsTool] (like {@see \App\Agent\Ui\EndTurnTool}) so `tools: true` autoloads it into the
 * garage toolbox; the bundle only autoconfigures class-level attributes.
 */
#[AsTool(
    'web_search',
    'Search the web for facts not available from the local garage tools or the parts catalog (a maintenance schedule/program, an interval, a fluid/spec, belt-vs-chain). Returns a grounded answer with source URLs. Last resort, max two searches per turn — put the exact vehicle (make, model, year, engine) in the query; if the first comes back empty, refine once. When it cannot answer it returns a leading token instead of prose: NO_RESULTS (the web had nothing — refining once is allowed), SEARCH_UNAVAILABLE (the service is down — do NOT retry, use the catalog) or SEARCH_BUDGET_SPENT (no searches left this turn).',
    'search',
)]
final readonly class WebSearchTool
{
    /**
     * Control-flow TOKENS the system prompt keys its retry rule on (config/packages/ai.yaml).
     *
     * These were French sentences until 2026-08-17, which coupled the agent's OUTPUT LANGUAGE to its
     * control flow: the prompt said "retry only if the first returns \"Aucun résultat\"", so the day the
     * agent answered in English the retry silently stopped firing. A token is language-free; the prose
     * around it is free to change and free to be translated.
     */
    public const string NO_RESULTS = 'NO_RESULTS';
    public const string UNAVAILABLE = 'SEARCH_UNAVAILABLE';
    public const string BUDGET_SPENT = 'SEARCH_BUDGET_SPENT';

    public function __construct(
        private WebSearchClient $client,
        private WebSearchBudget $budget,
    ) {
    }

    /**
     * @param string $query the search query — include make, model, year and engine verbatim
     */
    public function search(string $query): string
    {
        // Tool results are MODEL-facing, so they are English like the rest of the prompt — the user
        // never reads them, and the model's own instructions are English.
        if ($this->budget->exhausted()) {
            return self::BUDGET_SPENT.': the web-search budget for this turn is spent. Answer with what you'
                .' already have; do not search again.';
        }

        $this->budget->record(1);

        $result = $this->client->search($query);

        // Backend failure (provider overloaded/errored), not an empty web result: the model must NOT read
        // it as "the web has nothing" and give up. Steer it to the parts catalog (which lists brand refs)
        // or to known facts instead of burning the remaining budget on the same down service. The attempt
        // still spent a unit above, so two overloads exhaust the turn's budget and bound the retry loop.
        if ($result->unavailable) {
            return self::UNAVAILABLE.': the search service is temporarily overloaded — this is NOT an empty'
                .' web result. Do not retry the same search. For a part reference or an equivalent, use the'
                .' catalog (get_service_parts / list_articles), which gives the brand and the number;'
                .' otherwise answer from the facts already known.';
        }

        if ('' === trim($result->answer)) {
            return self::NO_RESULTS.': the web returned nothing for this search.';
        }

        if ([] === $result->citations) {
            return $result->answer;
        }

        $sources = array_map(
            static fn (Citation $c): string => \sprintf('- %s — %s', $c->title, $c->url),
            $result->citations,
        );

        return $result->answer."\n\nSources:\n".implode("\n", $sources);
    }
}
