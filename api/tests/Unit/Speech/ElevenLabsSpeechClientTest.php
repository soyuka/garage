<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\ElevenLabsSpeechClient;
use App\Speech\SpeechUnavailable;
use App\Speech\SpeechVoice;
use App\Speech\Wav;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * ElevenLabs now streams `pcm_24000` wrapped in a placeholder {@see Wav} header (2026-09-05), the
 * same shape `PocketTtsSpeechClient` wires — so the dashboard's streaming decoder sees one header
 * regardless of which backend spoke. Modelled on `PocketTtsSpeechClientTest`.
 */
final class ElevenLabsSpeechClientTest extends TestCase
{
    public function testItPostsToTheStreamEndpointWithPcm24000(): void
    {
        $sent = ['method' => '', 'url' => '', 'options' => []];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(['one', 'two']);
        });

        $this->client($http)->stream('Vidange enregistrée.', Locale::Fr);

        $this->assertSame('POST', $sent['method']);
        $this->assertStringEndsWith('/stream', strtok($sent['url'], '?'));
        $this->assertStringContainsString('output_format=pcm_24000', $sent['url']);
    }

    public function testTheJsonBodyCarriesTheLocaleAsLanguageCode(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return new MockResponse(['one']);
        });

        $this->client($http)->stream('Oil change logged.', Locale::En);

        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('en', $body['language_code']);
    }

    public function testTheJsonBodyCarriesTheConfiguredSpeedForElevenV3French(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return new MockResponse(['one']);
        });

        $this->client($http)->stream('La Mazda 6 prend 7,5 litres.', Locale::Fr);

        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('eleven_v3', $body['model_id']);
        $this->assertSame('fr', $body['language_code']);
        $this->assertSame(1.05, $body['voice_settings']['speed']);
    }

    public function testContentTypeIsWav(): void
    {
        $http = new MockHttpClient(new MockResponse(['one']));

        $stream = $this->client($http)->stream('x', Locale::Fr);

        $this->assertSame('audio/wav', $stream->contentType);
    }

    public function testFirstYieldedChunkIsTheWavHeader(): void
    {
        $http = new MockHttpClient(new MockResponse(['one', 'two']));

        $chunks = $this->client($http)->stream('x', Locale::Fr)->chunks();

        $this->assertSame(Wav::header(24000), $chunks->current());
    }

    /** The whole point of streaming: each vendor chunk must reach the caller AS IT ARRIVES, boundaries intact. */
    public function testSubsequentChunksMatchTheMockChunksInOrderWithBoundariesIntact(): void
    {
        $parts = ['one', 'two', 'three'];
        $http = new MockHttpClient(new MockResponse($parts));

        $wire = iterator_to_array($this->client($http)->stream('x', Locale::Fr)->chunks(), false);

        $this->assertSame(array_merge([Wav::header(24000)], $parts), $wire);
    }

    public function testTheGeneratorReturnsTheHeaderAndBodyWithRealLengthsStamped(): void
    {
        $pcm = str_repeat("\x01\x02", 512);
        $http = new MockHttpClient(new MockResponse([$pcm]));

        $chunks = $this->client($http)->stream('x', Locale::Fr)->chunks();
        iterator_to_array($chunks, false);
        $bytes = $chunks->getReturn();

        $this->assertSame(Wav::withRealLengths(Wav::header(24000).$pcm), $bytes, 'the return is header + body with the real RIFF/data sizes stamped in');
        $this->assertSame(\strlen($bytes) - 8, unpack('V', substr($bytes, 4, 4))[1], 'RIFF size = total - 8');
        $this->assertSame(\strlen($pcm), unpack('V', substr($bytes, 40, 4))[1], 'data size = N bytes of payload');
    }

    public function testHttp402IsUnavailable(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 402]));

        $this->expectException(SpeechUnavailable::class);

        $this->client($http)->stream('x', Locale::Fr);
    }

    public function testEmptyApiKeyIsUnavailable(): void
    {
        $http = new MockHttpClient(new MockResponse(['one']));

        $this->expectException(SpeechUnavailable::class);

        (new ElevenLabsSpeechClient($http, '', 'voice-id', 'eleven_flash_v2_5'))->stream('x', Locale::Fr);
    }

    public function testSpeedOutsideElevenLabsSupportedRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ElevenLabsSpeechClient(new MockHttpClient(), 'api-key', 'voice-id', 'eleven_v3', 1.21);
    }

    public function testStreamUsesTheRequestedVoiceOverTheConfiguredOne(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$sent): MockResponse {
            $sent[] = $url;

            return new MockResponse(['one']);
        });

        $this->client($http, voiceId: 'configured-voice')->stream('x', Locale::Fr, 'requested-voice');

        $this->assertStringContainsString('/requested-voice/', $sent[0]);
    }

    public function testVoicesKeepsOnlyTheMatchingLanguageOrNoLanguageLabelAtAll(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['voices' => [
            ['voice_id' => 'v-fr', 'name' => 'Claire - Conversationnel', 'labels' => ['language' => 'fr']],
            ['voice_id' => 'v-en', 'name' => 'Grace', 'labels' => ['language' => 'en']],
            ['voice_id' => 'v-none', 'name' => 'Neutral', 'labels' => ['accent' => 'neutral']],
        ]])));

        $voices = $this->client($http, voiceId: 'v-fr')->voices(Locale::Fr);

        $this->assertSame(['v-fr', 'v-none'], array_map(static fn (SpeechVoice $v): string => $v->id, $voices));
    }

    public function testVoiceLabelIsTrimmedAtTheFirstDashOrEnDash(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['voices' => [
            ['voice_id' => 'v1', 'name' => 'Claire - Conversationnel', 'labels' => ['language' => 'fr']],
            ['voice_id' => 'v2', 'name' => 'Estelle – Narration', 'labels' => ['language' => 'fr']],
            ['voice_id' => 'v3', 'name' => 'Louis', 'labels' => ['language' => 'fr']],
        ]])));

        $voices = $this->client($http, voiceId: 'v1')->voices(Locale::Fr);

        $this->assertSame(['Claire', 'Estelle', 'Louis'], array_map(static fn (SpeechVoice $v): string => $v->label, $voices));
    }

    public function testTheConfiguredDefaultVoiceSortsFirst(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['voices' => [
            ['voice_id' => 'v1', 'name' => 'Alice', 'labels' => ['language' => 'fr']],
            ['voice_id' => 'v2', 'name' => 'Bea', 'labels' => ['language' => 'fr']],
        ]])));

        $voices = $this->client($http, voiceId: 'v2')->voices(Locale::Fr);

        $this->assertSame('v2', $voices[0]->id, 'the configured default must sort first');
    }

    public function testVoicesFallsBackToTheConfiguredVoiceOnHttpFailure(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $voices = $this->client($http, voiceId: 'v-default')->voices(Locale::Fr);

        $this->assertEquals([new SpeechVoice('v-default', 'ElevenLabs')], $voices);
    }

    public function testVoicesFallsBackOnMalformedJson(): void
    {
        $http = new MockHttpClient(new MockResponse('not json'));

        $voices = $this->client($http, voiceId: 'v-default')->voices(Locale::Fr);

        $this->assertEquals([new SpeechVoice('v-default', 'ElevenLabs')], $voices);
    }

    public function testVoicesFallsBackWhenTheTransportThrows(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused.');
        });

        $voices = $this->client($http, voiceId: 'v-default')->voices(Locale::Fr);

        $this->assertEquals([new SpeechVoice('v-default', 'ElevenLabs')], $voices);
    }

    private function client(MockHttpClient $http, string $voiceId = 'voice-id'): ElevenLabsSpeechClient
    {
        return new ElevenLabsSpeechClient($http, 'api-key', $voiceId, 'eleven_v3', 1.05);
    }
}
