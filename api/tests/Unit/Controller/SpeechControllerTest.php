<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Agent\WebSearch\WebSearchBackend;
use App\Capability\Capabilities;
use App\Controller\SpeechController;
use App\I18n\Locale;
use App\Security\OwnerContext;
use App\Security\OwnerRateLimit;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;
use App\Speech\Verbalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * The controller used to buffer the whole line and stamp `audio/mpeg` on it. Both were ElevenLabs-
 * shaped assumptions: pocket-tts emits WAV, and it emits it as a STREAM — 80 ms of audio every
 * ~60 ms, first sound at ~250 ms (docs/archive/pocket-tts.md, 2026-09-05). Buffering turned that into a 3–10 s
 * wait for the first byte, which is exactly the gap the operator read as "the voice is broken".
 */
final class SpeechControllerTest extends TestCase
{
    public function testTheResponseIsStreamedChunkForChunkInTheBackendsFormat(): void
    {
        $response = $this->speak(['RIFF', 'fake'], 'audio/wav');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));
        $this->assertSame(['RIFF', 'fake'], self::flushed($response));
    }

    public function testAnMp3BackendStillAnnouncesMp3(): void
    {
        $response = $this->speak(['ID3fake'], 'audio/mpeg');

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
    }

    /**
     * A backend that dies mid-line: headers are out, so the only honest response is to stop where
     * it stopped. The browser plays what it received; the card is on screen regardless.
     */
    public function testABackendDyingMidStreamEndsTheResponseWhereItStopped(): void
    {
        $response = $this->speak(['RIFF', 'fake'], 'audio/wav', diesAfter: true);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(['RIFF', 'fake'], self::flushed($response));
    }

    public function testABackendThatCannotStartIsA503(): void
    {
        $speech = new class implements SpeechClient {
            public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
            {
                throw new SpeechUnavailable('the vendor could not be reached.');
            }

            public function voices(Locale $locale): array
            {
                return [];
            }
        };

        $response = (self::controller($speech))(self::request());

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    /**
     * @param list<string> $chunks
     */
    private function speak(array $chunks, string $contentType, bool $diesAfter = false): Response
    {
        $speech = new class($chunks, $contentType, $diesAfter) implements SpeechClient {
            /** @param list<string> $chunks */
            public function __construct(private readonly array $chunks, private readonly string $contentType, private readonly bool $diesAfter)
            {
            }

            public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
            {
                return new SpeechStream($this->contentType, (function (): \Generator {
                    yield from $this->chunks;

                    if ($this->diesAfter) {
                        throw new SpeechUnavailable('the vendor dropped the stream.');
                    }

                    return implode('', $this->chunks);
                })());
            }

            public function voices(Locale $locale): array
            {
                return [];
            }
        };

        return (self::controller($speech))(self::request());
    }

    private static function request(): Request
    {
        return Request::create('/tts', 'POST', content: json_encode(['text' => 'Vidange enregistrée.']));
    }

    /**
     * Each chunk the controller FLUSHES, as a separate element — which is what proves the streaming:
     * a controller that buffered would flush once. The callback flushes the innermost output buffer
     * into the one below it, so two nested buffers separate "flushed" from "still held".
     *
     * @return list<string>
     */
    private static function flushed(StreamedResponse $response): array
    {
        $flushes = [];
        ob_start(static function (string $buffer) use (&$flushes): string {
            if ('' !== $buffer) {
                $flushes[] = $buffer;
            }

            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return $flushes;
    }

    private static function controller(SpeechClient $speech): SpeechController
    {
        $owner = new OwnerContext(new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => new TokenStorage()])), 'owner@example.com');
        $limiter = new RateLimiterFactory(['id' => 'speech', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '1 hour'], new InMemoryStorage());

        return new SpeechController($speech, new Verbalizer(), new Capabilities('test-anthropic-key', 'test-xi-key', 'test-gemini-key', WebSearchBackend::Gemini, 'test-agent-key'), new OwnerRateLimit($owner), $limiter);
    }
}
