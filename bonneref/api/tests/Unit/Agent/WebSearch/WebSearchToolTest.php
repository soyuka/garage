<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\WebSearch;

use App\Agent\Finder\FinderVehicle;
use App\Agent\WebSearch\Citation;
use App\Agent\WebSearch\WebSearchClient;
use App\Agent\WebSearch\WebSearchGate;
use App\Agent\WebSearch\WebSearchResult;
use App\Agent\WebSearch\WebSearchTool;
use App\Agent\WebSearchBudget;
use PHPUnit\Framework\TestCase;

final class WebSearchToolTest extends TestCase
{
    public function testSearchesRecordsBudgetAndFormatsAnswerWithSources(): void
    {
        $client = $this->createMock(WebSearchClient::class);
        $client->expects($this->once())
            ->method('search')
            ->with('Mazda 6 2007 2.0 distribution chaine')
            ->willReturn(new WebSearchResult(
                'La distribution est par chaîne, sans intervalle de remplacement.',
                [
                    new Citation('https://example.com/mazda6', 'Mazda 6 entretien'),
                    new Citation('https://example.com/chaine', 'Distribution'),
                ],
            ));

        $budget = new WebSearchBudget(1);
        $out = (new WebSearchTool($client, $budget, self::openGate()))->search('Mazda 6 2007 2.0 distribution chaine');

        $this->assertStringContainsString('La distribution est par chaîne', $out);
        $this->assertStringContainsString('https://example.com/mazda6', $out);
        $this->assertStringContainsString('Mazda 6 entretien', $out);
        $this->assertStringContainsString('https://example.com/chaine', $out);
        $this->assertTrue($budget->exhausted(), 'a performed search must spend the per-turn budget');
    }

    public function testRefusesWhenBudgetExhaustedWithoutCallingClient(): void
    {
        $client = $this->createMock(WebSearchClient::class);
        $client->expects($this->never())->method('search');

        $budget = new WebSearchBudget(1);
        $budget->record(1); // already spent this turn

        $out = (new WebSearchTool($client, $budget, self::openGate()))->search('anything');

        $this->assertStringNotContainsString('http', $out);
        $this->assertMatchesRegularExpression('/recherche|search/i', $out);
    }

    public function testReturnsAnswerEvenWithNoCitations(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $client->method('search')->willReturn(new WebSearchResult('Réponse sans source.'));

        $out = (new WebSearchTool($client, new WebSearchBudget(1), self::openGate()))->search('Mazda filtre');

        $this->assertStringContainsString('Réponse sans source.', $out);
    }

    public function testUnavailableBackendReturnsDistinctOverloadMessageAndStillSpendsBudget(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $client->method('search')->willReturn(new WebSearchResult('', [], unavailable: true));

        $budget = new WebSearchBudget(1);
        $out = (new WebSearchTool($client, $budget, self::openGate()))->search('Mazda 6 équivalent Bosch filtres');

        // Distinct from the genuine "no result" message: the model must know the SERVICE failed, not that
        // the web had nothing — and be steered to the parts catalog rather than retrying blindly.
        $this->assertStringContainsString(WebSearchTool::UNAVAILABLE, $out);
        $this->assertStringNotContainsString(WebSearchTool::NO_RESULTS, $out, 'an overload is not "the web has nothing"');
        $this->assertStringContainsString('catalog', $out);
        $this->assertStringNotContainsString('http', $out);
        $this->assertTrue($budget->exhausted(), 'an unavailable backend still spends one unit to bound retries');
    }

    public function testEmptyAnswerReturnsExplicitNoResultMessageNotBlank(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $client->method('search')->willReturn(new WebSearchResult('   '));

        $budget = new WebSearchBudget(2);
        $out = (new WebSearchTool($client, $budget, self::openGate()))->search('Mazda 6 2007 programme entretien');

        $this->assertNotSame('', trim($out), 'an empty search must not return a blank string to the orchestrator');
        $this->assertStringContainsString(WebSearchTool::NO_RESULTS, $out);
        $this->assertStringNotContainsString('http', $out);
        $this->assertStringNotContainsString(WebSearchTool::BUDGET_SPENT, $out, 'no-result is distinct from budget-exhausted');
        $this->assertFalse($budget->exhausted(), 'a single empty search of two must leave a retry');
    }

    /**
     * The retry rule in ai.yaml keys on these EXACT tokens. They used to be French prose
     * ("Aucun résultat"), which made the agent's output language and its control flow the same string:
     * translate the prose and the retry silently stops firing. The token is the contract; the sentence
     * beside it is free to change, in any language.
     */
    public function testTheControlFlowTokensAreStableAndDistinct(): void
    {
        $this->assertSame('NO_RESULTS', WebSearchTool::NO_RESULTS);
        $this->assertSame('SEARCH_UNAVAILABLE', WebSearchTool::UNAVAILABLE);
        $this->assertSame('SEARCH_BUDGET_SPENT', WebSearchTool::BUDGET_SPENT);
    }

    public function testBudgetExhaustedAnnouncesItsOwnToken(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $budget = new WebSearchBudget(0);
        $out = (new WebSearchTool($client, $budget, self::openGate()))->search('anything');

        $this->assertStringContainsString(WebSearchTool::BUDGET_SPENT, $out);
        $this->assertStringNotContainsString(WebSearchTool::NO_RESULTS, $out);
    }

    private static function openGate(): WebSearchGate
    {
        $gate = new WebSearchGate();
        $gate->bindVehicle(new FinderVehicle(19107, make: 'MAZDA'));
        $gate->recordCatalogueMiss();

        return $gate;
    }
}
