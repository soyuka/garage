<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\WebSearch;

use App\Agent\WebSearch\GeminiWebSearchClient;
use App\Agent\WebSearch\WebSearchTracer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

final class GeminiWebSearchClientTest extends TestCase
{
    public function testInvokesGeminiWithGoogleSearchAndParsesAnswerAndCitations(): void
    {
        $captured = [];
        // Gemini grounded generateContent shape: text lives in candidates[0].content.parts[],
        // sources in candidates[0].groundingMetadata.groundingChunks[].web.{uri,title}.
        $platform = $this->platform([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Chaîne de distribution. '],
                    ['text' => 'Pas de remplacement programmé.'],
                ]],
                'groundingMetadata' => ['groundingChunks' => [
                    ['web' => ['uri' => 'https://a.example', 'title' => 'A']],
                    ['web' => ['uri' => 'https://b.example', 'title' => 'B']],
                    ['web' => ['uri' => 'https://a.example', 'title' => 'A dup']],
                ]],
            ]],
        ], $captured);

        $result = (new GeminiWebSearchClient($platform, 'gemini-2.5-flash'))
            ->search('Mazda 6 distribution');

        // Answer is the concatenation of the text parts.
        $this->assertSame('Chaîne de distribution. Pas de remplacement programmé.', $result->answer);

        // Sources come from the grounding chunks, deduped by URL (uri mapped to Citation::url).
        $this->assertCount(2, $result->citations);
        $this->assertSame('https://a.example', $result->citations[0]->url);
        $this->assertSame('A', $result->citations[0]->title);
        $this->assertSame('https://b.example', $result->citations[1]->url);

        // Request shape: the configured Gemini model, non-stream, google_search server tool declared.
        $this->assertSame('gemini-2.5-flash', $captured['model']);
        $this->assertInstanceOf(MessageBag::class, $captured['input']);
        $this->assertNotTrue($captured['options']['stream'] ?? false, 'the side search call must be non-stream');
        $this->assertTrue($captured['options']['server_tools']['google_search'] ?? false);
    }

    public function testReturnsAnswerWithoutCitationsWhenGroundingMetadataAbsent(): void
    {
        $captured = [];
        $platform = $this->platform([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Réponse.']]],
            ]],
        ], $captured);

        $result = (new GeminiWebSearchClient($platform, 'gemini-2.5-flash'))->search('q');

        $this->assertSame('Réponse.', $result->answer);
        $this->assertSame([], $result->citations);
    }

    public function testTracesTheRawGeminiBodyForTheJournal(): void
    {
        $captured = [];
        // An EMPTY-answer body — the exact shape that produced a silent noop turn — must still be traced
        // so the raw response is recoverable from the journal even when no text/citations come back.
        $body = ['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'MAX_TOKENS']]];
        $platform = $this->platform($body, $captured);

        $tracer = $this->createMock(WebSearchTracer::class);
        $tracer->expects($this->once())
            ->method('recordWebSearchResponse')
            ->with('gemini', 'Mazda 6 2007 programme entretien', $body);

        $result = (new GeminiWebSearchClient($platform, 'gemini-2.5-flash', $tracer))
            ->search('Mazda 6 2007 programme entretien');

        $this->assertSame('', $result->answer);
        $this->assertSame([], $result->citations);
    }

    public function testBackendErrorBodyIsReportedUnavailableNotEmpty(): void
    {
        $captured = [];
        // The exact shape an overloaded Gemini returns (HTTP 503): an `error` envelope, no `candidates`.
        // It must be distinguished from a genuine empty result so the orchestrator can pivot rather than
        // read it as "the web has nothing".
        $body = ['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']];
        $platform = $this->platform($body, $captured);

        $tracer = $this->createMock(WebSearchTracer::class);
        $tracer->expects($this->once())
            ->method('recordWebSearchResponse')
            ->with('gemini', 'q', $body);

        $result = (new GeminiWebSearchClient($platform, 'gemini-2.5-flash', $tracer))->search('q');

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
            /** @param array<string, mixed> $data */
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
            static function (string|object $model, mixed $input, array $options = []) use ($deferred, &$captured): DeferredResult {
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
