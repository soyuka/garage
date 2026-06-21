<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\QwenSearchLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

final class QwenSearchLoggerTest extends TestCase
{
    public function testLogsSearchResultUrlsWhenSearchInfoPresent(): void
    {
        $raw = $this->rawResult([
            'search_info' => ['search_results' => [
                ['url' => 'https://example.com/a'],
                ['url' => 'https://example.com/b'],
            ]],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with(
            $this->stringContains('result'),
            $this->callback(static fn (array $ctx): bool => 2 === $ctx['count']
                && str_contains((string) $ctx['urls'], 'https://example.com/a')),
        );

        $this->decorator($raw, $logger)->invoke('qwen', 'hi', ['enable_search' => true]);
    }

    public function testLogsRequestedButUnstructuredWhenSearchAskedButNoSearchInfo(): void
    {
        // This MaaS endpoint honours enable_search by injecting results into the prompt context and
        // returns NO top-level search_info — the logger must still leave a trace, not go silent.
        $raw = $this->rawResult([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 3755],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with(
            $this->stringContains('no search_info'),
            $this->callback(static fn (array $ctx): bool => 3755 === $ctx['promptTokens']),
        );

        $this->decorator($raw, $logger)->invoke('qwen', 'hi', ['enable_search' => true]);
    }

    public function testDoesNotLogWhenSearchNotRequestedAndNoSearchInfo(): void
    {
        $raw = $this->rawResult(['choices' => [['message' => ['content' => 'ok']]]]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $this->decorator($raw, $logger)->invoke('qwen', 'hi');
    }

    public function testDoesNotReadRawOnStreamingCall(): void
    {
        $raw = $this->rawResult(['choices' => []]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $this->decorator($raw, $logger)->invoke('qwen', 'hi', ['enable_search' => true, 'stream' => true]);

        $this->assertSame(0, $raw->reads, 'streaming must not force the raw HTTP fetch');
    }

    private function decorator(RawResultInterface $raw, LoggerInterface $logger): QwenSearchLogger
    {
        $deferred = new DeferredResult($this->createStub(ResultConverterInterface::class), $raw);

        $inner = $this->createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturn($deferred);
        $inner->method('getModelCatalog')->willReturn($this->createStub(ModelCatalogInterface::class));

        return new QwenSearchLogger($inner, $logger);
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
