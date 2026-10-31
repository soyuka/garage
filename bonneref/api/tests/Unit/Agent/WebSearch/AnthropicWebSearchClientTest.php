<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\WebSearch;

use App\Agent\WebSearch\AnthropicWebSearchClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

final class AnthropicWebSearchClientTest extends TestCase
{
    public function testInvokesHaikuWithWebSearchToolAndParsesAnswerAndCitations(): void
    {
        $captured = [];
        $platform = $this->platform([
            'content' => [
                ['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 'q']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 't', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://a.example', 'title' => 'A'],
                    ['type' => 'web_search_result', 'url' => 'https://b.example', 'title' => 'B'],
                ]],
                ['type' => 'text', 'text' => 'Chaîne de distribution. ', 'citations' => [
                    ['type' => 'web_search_result_location', 'url' => 'https://a.example', 'title' => 'A'],
                ]],
                ['type' => 'text', 'text' => 'Pas de remplacement programmé.'],
            ],
        ], $captured);

        $result = (new AnthropicWebSearchClient($platform, 'claude-haiku-4-5?max_tokens=2048'))
            ->search('Mazda 6 distribution');

        // Answer is the concatenation of the text blocks.
        $this->assertSame('Chaîne de distribution. Pas de remplacement programmé.', $result->answer);

        // Cited sources come from the text-block citations (the ones actually used), deduped by URL.
        $this->assertCount(1, $result->citations);
        $this->assertSame('https://a.example', $result->citations[0]->url);
        $this->assertSame('A', $result->citations[0]->title);

        // Request shape: the configured Haiku model, non-stream, native web_search declared.
        $this->assertSame('claude-haiku-4-5?max_tokens=2048', $captured['model']);
        $this->assertInstanceOf(MessageBag::class, $captured['input']);
        $this->assertNotTrue($captured['options']['stream'] ?? false, 'the side search call must be non-stream');
        $this->assertSame('web_search', $captured['options']['server_tools'][0]['name'] ?? null);
    }

    public function testFallsBackToResultBlockSourcesWhenNoTextCitations(): void
    {
        $captured = [];
        $platform = $this->platform([
            'content' => [
                ['type' => 'web_search_tool_result', 'tool_use_id' => 't', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://only.example', 'title' => 'Only'],
                ]],
                ['type' => 'text', 'text' => 'Réponse.'],
            ],
        ], $captured);

        $result = (new AnthropicWebSearchClient($platform, 'claude-haiku-4-5'))->search('q');

        $this->assertSame('Réponse.', $result->answer);
        $this->assertCount(1, $result->citations);
        $this->assertSame('https://only.example', $result->citations[0]->url);
    }

    public function testBackendErrorBodyIsReportedUnavailableNotEmpty(): void
    {
        $captured = [];
        // Anthropic's overloaded shape: a top-level error envelope, no `content`. Like the Gemini case,
        // it must be flagged unavailable so the tool returns a distinct signal to the orchestrator.
        $platform = $this->platform([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], $captured);

        $result = (new AnthropicWebSearchClient($platform, 'claude-haiku-4-5'))->search('q');

        $this->assertTrue($result->unavailable, 'a backend error envelope must mark the result unavailable');
        $this->assertSame('', $result->answer);
        $this->assertSame([], $result->citations);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $captured filled with the model/input/options passed to invoke()
     */
    private function platform(array $data, array &$captured): PlatformInterface
    {
        $raw = new class($data) implements RawResultInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(): array
            {
                return $this->data;
            }

            public function getDataStream(): iterable
            {
                yield $this->data;
            }

            public function getObject(): object
            {
                return (object) $this->data;
            }
        };

        $deferred = new DeferredResult($this->createStub(ResultConverterInterface::class), $raw);

        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->once())->method('invoke')->willReturnCallback(
            function (string|object $model, mixed $input, array $options = []) use ($deferred, &$captured): DeferredResult {
                $captured['model'] = $model;
                $captured['input'] = $input;
                $captured['options'] = $options;

                return $deferred;
            },
        );
        $platform->method('getModelCatalog')->willReturn($this->createStub(ModelCatalogInterface::class));

        return $platform;
    }
}
