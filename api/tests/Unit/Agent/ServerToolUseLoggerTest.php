<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\I18n\LocaleContext;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Agent\Journal\TurnJournal;
use App\Agent\ServerToolUseLogger;
use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\TestDashboardChannel;
use App\Agent\Ui\StepNarrator;
use App\Agent\WebSearchBudget;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class ServerToolUseLoggerTest extends TestCase
{
    public function testLogsWebSearchCountAndQueries(): void
    {
        $raw = $this->rawResult([
            'content' => [
                ['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 'Mazda6 2007 distribution chaine 250000 km']],
                ['type' => 'text', 'text' => 'ok'],
            ],
            'usage' => ['server_tool_use' => ['web_search_requests' => 1]],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with(
            $this->stringContains('web_search'),
            $this->callback(static fn (array $ctx): bool => 1 === $ctx['count']
                && 'Mazda6 2007 distribution chaine 250000 km' === $ctx['queries']),
        );

        $this->decorator($raw, $logger)->invoke('claude', 'hi');
    }

    public function testDoesNotLogWhenNoServerToolUse(): void
    {
        $raw = $this->rawResult([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['server_tool_use' => ['web_search_requests' => 0]],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $this->decorator($raw, $logger)->invoke('claude', 'hi');
    }

    public function testStreamingFeedsBudgetFromTokenUsageWithoutReadingRaw(): void
    {
        $raw = $this->rawResult(['content' => [], 'usage' => []]);

        // The Anthropic ResultConverter surfaces server_tool_use.web_search_requests as a TokenUsage
        // delta's toolTokens; mirror that shape in the streamed result.
        $stream = new StreamResult((static function (): \Generator {
            yield new TokenUsage(promptTokens: 10); // message_start: no web_search yet
            yield new TokenUsage(completionTokens: 5, toolTokens: 1); // message_delta: one search
        })());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $budget = new WebSearchBudget(1);
        $published = new \ArrayObject();
        $result = $this->decorator($raw, $logger, $budget, new PlainConverter($stream), $published)
            ->invoke('claude', 'hi', ['stream' => true]);

        // The worker drains the stream; that is when the listener fires.
        foreach ($result->getResult()->getContent() as $delta) {
            unset($delta);
        }

        $this->assertSame(0, $raw->reads, 'streaming must not force the raw HTTP fetch');
        $this->assertTrue($budget->exhausted(), 'the streamed web_search count must feed the budget');
        // The same toolTokens delta also narrates the search on the dashboard heartbeat.
        $this->assertSame([['type' => 'status', 'message' => 'Je cherche en ligne…', 'tool' => 'web_search']], $published->getArrayCopy());
    }

    /**
     * @param \ArrayObject<int, array<string, mixed>>|null $published filled with each narration frame
     */
    private function decorator(
        RawResultInterface $raw,
        LoggerInterface $logger,
        ?WebSearchBudget $budget = null,
        ?ResultConverterInterface $converter = null,
        ?\ArrayObject $published = null,
    ): ServerToolUseLogger {
        $deferred = new DeferredResult($converter ?? $this->createStub(ResultConverterInterface::class), $raw);

        $inner = $this->createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturn($deferred);
        $inner->method('getModelCatalog')->willReturn($this->createStub(ModelCatalogInterface::class));

        $published ??= new \ArrayObject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $journal = new TurnJournal($this->createStub(DocumentManager::class), $this->createStub(LoggerInterface::class));

        return new ServerToolUseLogger($inner, $logger, $budget ?? new WebSearchBudget(1), new StepNarrator($tools, new LocaleContext(new RequestStack())), $journal);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rawResult(array $data): RawResultInterface
    {
        return new class($data) implements RawResultInterface {
            public int $reads = 0;

            public function __construct(private readonly array $data)
            {
            }

            public function getData(): array
            {
                ++$this->reads;

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
    }
}
