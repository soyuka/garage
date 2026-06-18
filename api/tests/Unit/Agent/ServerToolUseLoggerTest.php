<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\ServerToolUseLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

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
                && ['Mazda6 2007 distribution chaine 250000 km'] === $ctx['queries']),
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

    public function testDoesNotReadRawWhenStreaming(): void
    {
        $raw = $this->rawResult(['content' => [], 'usage' => []]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $this->decorator($raw, $logger)->invoke('claude', 'hi', ['stream' => true]);

        $this->assertSame(0, $raw->reads, 'streaming must not force the raw HTTP fetch');
    }

    private function decorator(RawResultInterface $raw, LoggerInterface $logger): ServerToolUseLogger
    {
        $deferred = new DeferredResult($this->createStub(ResultConverterInterface::class), $raw);

        $inner = $this->createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturn($deferred);
        $inner->method('getModelCatalog')->willReturn($this->createStub(ModelCatalogInterface::class));

        return new ServerToolUseLogger($inner, $logger);
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
