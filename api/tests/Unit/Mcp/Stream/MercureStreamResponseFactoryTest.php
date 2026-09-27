<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Stream;

use App\Mcp\Stream\MercureStreamResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class MercureStreamResponseFactoryTest extends TestCase
{
    private const string TOPIC = 'https://garage.soyuka.me/mcp/streams/01JH2X';
    private const string TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJtZXJjdXJlIjp7fX0.c2ln';

    private Psr17Factory $psr17;
    private MercureStreamResponseFactory $factory;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->factory = new MercureStreamResponseFactory($this->psr17);
    }

    public function testAdvertisesTheTopicAndTokenAsAnEventStream(): void
    {
        $delegated = $this->factory->delegate($this->response(), self::TOPIC, self::TOKEN);

        $this->assertSame(self::TOPIC, $delegated->getHeaderLine('Mercure-Stream'));
        $this->assertSame(self::TOKEN, $delegated->getHeaderLine('Mercure-Stream-Token'));
        $this->assertSame('text/event-stream', $delegated->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $delegated->getHeaderLine('Cache-Control'));
        $this->assertSame(200, $delegated->getStatusCode());
    }

    public function testEmptiesTheBodySoNoPhpThreadStreams(): void
    {
        $response = $this->response()->withBody($this->psr17->createStream('{"jsonrpc":"2.0"}'));

        $this->assertSame('', (string) $this->factory->delegate($response, self::TOPIC, self::TOKEN)->getBody());
    }

    /**
     * Caddy replaces the body, so any header describing the old one would describe the wrong bytes.
     */
    #[DataProvider('entityHeaders')]
    public function testStripsEntityHeaders(string $header, string $value): void
    {
        $response = $this->response()->withHeader($header, $value);

        $this->assertFalse($this->factory->delegate($response, self::TOPIC, self::TOKEN)->hasHeader($header));
    }

    public static function entityHeaders(): iterable
    {
        yield 'content length' => ['Content-Length', '17'];
        yield 'etag' => ['ETag', '"abc"'];
        yield 'content encoding' => ['Content-Encoding', 'gzip'];
        yield 'transfer encoding' => ['Transfer-Encoding', 'chunked'];
        yield 'repr digest' => ['Repr-Digest', 'sha-256=:abc:'];
        yield 'last modified' => ['Last-Modified', 'Wed, 27 Sep 2026 00:00:00 GMT'];
    }

    #[DataProvider('preservedHeaders')]
    public function testPreservesSessionAndSecurityHeaders(string $header, string $value): void
    {
        $response = $this->response()->withHeader($header, $value);

        $this->assertSame($value, $this->factory->delegate($response, self::TOPIC, self::TOKEN)->getHeaderLine($header));
    }

    public static function preservedHeaders(): iterable
    {
        yield 'session' => ['Mcp-Session-Id', '018f-cafe'];
        yield 'cors' => ['Access-Control-Allow-Origin', 'https://garage.soyuka.me'];
        yield 'nosniff' => ['X-Content-Type-Options', 'nosniff'];
    }

    /**
     * Delegating a non-200 would turn an application error into a stream the client waits on forever.
     */
    public function testRefusesAnythingButOk(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->delegate($this->response()->withStatus(202), self::TOPIC, self::TOKEN);
    }

    #[DataProvider('invalidTopics')]
    public function testRefusesUnusableTopic(string $topic): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->delegate($this->response(), $topic, self::TOKEN);
    }

    public static function invalidTopics(): iterable
    {
        yield 'empty' => [''];
        yield 'header injection' => ["a\r\nX-Injected: 1"];
        yield 'newline' => ["a\nb"];
        yield 'wildcard' => ['*'];
        yield 'too long' => [str_repeat('a', 4097)];
    }

    #[DataProvider('invalidTokens')]
    public function testRefusesUnusableToken(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->delegate($this->response(), self::TOPIC, $token);
    }

    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'header injection' => ["abc\r\nX-Injected: 1"];
        yield 'space' => ['abc def'];
    }

    private function response(): ResponseInterface
    {
        return $this->psr17->createResponse(200);
    }
}
