<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Speech\Wav;
use App\Tests\Mock\RecordingSpeechHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /tts — the browser's only route to a voice. The key stays server-side and the endpoint needs
 * a token, because an open TTS proxy is somebody else's credits being spent.
 *
 * The vendor transport is {@see RecordingSpeechHttpClient}; no test reaches api.elevenlabs.io.
 */
final class SpeechTest extends AppTestCase
{
    /** A toast is one line. A paragraph arriving here is an upstream bug, not a long answer. */
    private const int OVER_CAP = 401;

    public function testAnonymousIsRefused(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tts', ['json' => ['text' => 'Vidange enregistrée.']]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame([], $this->transport()->requests, 'An unauthenticated caller must not reach the vendor.');
    }

    /**
     * ElevenLabs now streams `pcm_24000` wrapped in a placeholder WAV header (2026-09-05), the same
     * shape `pocket-tts` wires, so `/tts` answers `audio/wav` regardless of the selected backend. The
     * wire itself carries the PLACEHOLDER sizes — only the completed body the cache stores gets the
     * real ones stamped in ({@see Wav::withRealLengths}) — so what the client receives here is the
     * header verbatim followed by the vendor's fake mp3 stand-in bytes, concatenated.
     */
    public function testAuthedRequestReturnsTheSynthesizedAudio(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tts', [
            'json' => ['text' => 'Vidange enregistrée.'],
            'headers' => static::authHeaders(),
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'audio/wav');
        $this->assertSame(Wav::header(24000).RecordingSpeechHttpClient::AUDIO, $client->getResponse()->getContent());
    }

    public function testOutgoingRequestCarriesTheKeyVoiceAndModel(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tts', [
            'json' => ['text' => 'Vidange enregistrée.'],
            'headers' => static::authHeaders(),
        ]);

        $transport = $this->transport();
        $sent = $transport->lastRequest();

        $this->assertNotNull($sent, 'The endpoint must have called the vendor.');
        $this->assertSame('POST', $sent['method']);
        $this->assertStringContainsString('/v1/text-to-speech/test-voice-id', $sent['url']);
        $this->assertContains('xi-api-key: test-xi-key', $sent['options']['headers']);

        $body = $transport->lastBody();
        $this->assertSame('Vidange enregistrée.', $body['text']);
        $this->assertSame('eleven_flash_v2_5', $body['model_id']);
    }

    /**
     * The spoken line follows the interface: the browser already announces its language on every
     * request, so `/tts` reads it there rather than adding a field the dashboard would have to send
     * (CONTRACT.md § the audition adds no wire field).
     *
     * `eleven_flash_v2_5` is multilingual; `language_code` is what pins which language it reads the
     * text AS, and without it the voice's own default wins — which is how a French sentence ends up
     * read as though it were English.
     */
    #[DataProvider('spokenLocales')]
    public function testTheSpokenLanguageFollowsTheRequestLocale(string $acceptLanguage, string $expected): void
    {
        $client = static::createClient();
        $client->request('POST', '/tts', [
            'json' => ['text' => 'Vidange enregistrée.'],
            'headers' => static::authHeaders() + ['Accept-Language' => $acceptLanguage],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($expected, $this->transport()->lastBody()['language_code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function spokenLocales(): iterable
    {
        yield 'english' => ['en-GB,en;q=0.9', 'en'];
        yield 'french' => ['fr-FR,fr;q=0.9', 'fr'];
        // An unsupported language must not reach the vendor as-is: it falls back to the app default.
        yield 'unsupported falls back to French' => ['de-DE', 'fr'];
    }

    // The header-less case is deliberately NOT tested here: Symfony's Request::create() injects a
    // default `Accept-Language: en-us,en;q=0.5` into every test request, so "no header" cannot be
    // expressed through this transport. The fallback it would prove lives in
    // {@see \App\Tests\Unit\I18n\LocaleTest} instead, which is where the decision actually is.

    #[DataProvider('unspeakableText')]
    public function testUnspeakableTextIsRefusedWithoutSpendingACredit(string $text): void
    {
        $client = static::createClient();
        $client->request('POST', '/tts', [
            'json' => ['text' => $text],
            'headers' => static::authHeaders(),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame([], $this->transport()->requests, 'A rejected line must not reach the vendor.');
    }

    /** @return iterable<string, array{string}> */
    public static function unspeakableText(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'over the cap' => [str_repeat('a', self::OVER_CAP)];
    }

    public function testAVendorFailureIsSilentNotABrokenDashboard(): void
    {
        $client = static::createClient();
        // 401 bad key, 402 free tier reaching for a library voice, 429 rate limit — same outcome.
        $this->transport()->status = 429;

        $client->request('POST', '/tts', [
            'json' => ['text' => 'Vidange enregistrée.'],
            'headers' => static::authHeaders(),
        ]);

        // Never a 500: the notice card is already on screen, so silence is a degraded mode.
        $this->assertResponseStatusCodeSame(503);
        $this->assertSame('', $client->getResponse()->getContent(false));
    }

    private function transport(): RecordingSpeechHttpClient
    {
        return static::getContainer()->get(RecordingSpeechHttpClient::class);
    }
}
