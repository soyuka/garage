<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\WebSearch;

use App\Agent\WebSearch\Citation;
use App\Agent\WebSearch\SelectableWebSearchClient;
use App\Agent\WebSearch\WebSearchBackend;
use App\Agent\WebSearch\WebSearchClient;
use App\Agent\WebSearch\WebSearchResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class SelectableWebSearchClientTest extends TestCase
{
    public function testDelegatesToTheClientForTheConfiguredBackend(): void
    {
        $expected = new WebSearchResult('grounded', [new Citation('https://x.example', 'X')]);

        $gemini = $this->createMock(WebSearchClient::class);
        $gemini->expects($this->once())->method('search')->with('q')->willReturn($expected);

        $anthropic = $this->createMock(WebSearchClient::class);
        $anthropic->expects($this->never())->method('search');

        $client = new SelectableWebSearchClient(
            $this->locator(['anthropic' => $anthropic, 'gemini' => $gemini]),
            WebSearchBackend::Gemini,
        );

        $this->assertSame($expected, $client->search('q'));
    }

    public function testResolvesOnlyTheSelectedBackendNotBoth(): void
    {
        // The non-selected client must never be pulled from the locator (its platform stays lazy/unused).
        $anthropic = $this->createMock(WebSearchClient::class);
        $anthropic->expects($this->once())->method('search')->willReturn(new WebSearchResult('a'));

        $locator = $this->createMock(ContainerInterface::class);
        $locator->expects($this->once())->method('get')->with('anthropic')->willReturn($anthropic);

        (new SelectableWebSearchClient($locator, WebSearchBackend::Anthropic))->search('q');
    }

    /**
     * @param array<string, WebSearchClient> $clients
     */
    private function locator(array $clients): ContainerInterface
    {
        return new class($clients) implements ContainerInterface {
            /**
             * @param array<string, WebSearchClient> $clients
             */
            public function __construct(private readonly array $clients)
            {
            }

            public function get(string $id): WebSearchClient
            {
                return $this->clients[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->clients[$id]);
            }
        };
    }
}
