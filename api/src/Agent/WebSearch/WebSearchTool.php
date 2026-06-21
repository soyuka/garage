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
 * pass), so a search is allowed only while the turn's {@see WebSearchBudget} has room — reset per turn
 * by {@see \App\MessageHandler\RunAgentTurnHandler}. Once spent, the tool returns a plain instruction
 * to answer from what's already known instead of searching again.
 *
 * Class-level #[AsTool] (like {@see \App\Agent\Ui\EndTurnTool}) so `tools: true` autoloads it into the
 * garage toolbox; the bundle only autoconfigures class-level attributes.
 */
#[AsTool(
    'web_search',
    'Search the web for facts not available from the local garage tools or the parts catalog (a maintenance schedule/program, an interval, a fluid/spec, belt-vs-chain). Returns a grounded answer with source URLs. Last resort, one search per turn — put the exact vehicle (make, model, year, engine) in the query.',
    'search',
)]
final readonly class WebSearchTool
{
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
        if ($this->budget->exhausted()) {
            return 'Limite de recherche web atteinte pour ce tour : réponds avec les informations déjà obtenues, sans relancer de recherche.';
        }

        $this->budget->record(1);

        $result = $this->client->search($query);

        if ([] === $result->citations) {
            return $result->answer;
        }

        $sources = array_map(
            static fn (Citation $c): string => \sprintf('- %s — %s', $c->title, $c->url),
            $result->citations,
        );

        return $result->answer."\n\nSources :\n".implode("\n", $sources);
    }
}
