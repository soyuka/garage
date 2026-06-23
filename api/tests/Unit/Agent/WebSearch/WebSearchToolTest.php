<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\WebSearch;

use App\Agent\WebSearch\Citation;
use App\Agent\WebSearch\WebSearchClient;
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
        $out = (new WebSearchTool($client, $budget))->search('Mazda 6 2007 2.0 distribution chaine');

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

        $out = (new WebSearchTool($client, $budget))->search('anything');

        $this->assertStringNotContainsString('http', $out);
        $this->assertMatchesRegularExpression('/recherche|search/i', $out);
    }

    public function testReturnsAnswerEvenWithNoCitations(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $client->method('search')->willReturn(new WebSearchResult('Réponse sans source.'));

        $out = (new WebSearchTool($client, new WebSearchBudget(1)))->search('q');

        $this->assertStringContainsString('Réponse sans source.', $out);
    }

    public function testEmptyAnswerReturnsExplicitNoResultMessageNotBlank(): void
    {
        $client = $this->createStub(WebSearchClient::class);
        $client->method('search')->willReturn(new WebSearchResult('   '));

        $budget = new WebSearchBudget(2);
        $out = (new WebSearchTool($client, $budget))->search('Mazda 6 2007 programme entretien');

        $this->assertNotSame('', trim($out), 'an empty search must not return a blank string to the orchestrator');
        $this->assertMatchesRegularExpression('/aucun résultat|no result/i', $out);
        $this->assertStringNotContainsString('http', $out);
        $this->assertStringNotContainsString('Limite de recherche', $out, 'no-result is distinct from budget-exhausted');
        $this->assertFalse($budget->exhausted(), 'a single empty search of two must leave a retry');
    }
}
