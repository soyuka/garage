<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\PocketTtsSpeechClient;
use App\Speech\SpeechUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PocketTtsSpeechClientTest extends TestCase
{
    private const string AUDIO = "RIFF\x00\x00\x00\x00WAVEfake";

    public function testItPostsToTheServerConfiguredForThatLocaleAndReturnsWav(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(self::AUDIO);
        });

        $audio = $this->client($http)->synthesize('Vidange enregistrée.', Locale::Fr);

        $this->assertSame('POST', $sent['method']);
        $this->assertSame('http://pocket-tts-fr:8000/tts', $sent['url']);
        $this->assertSame(self::AUDIO, $audio->bytes);
        // pocket-tts emits 24 kHz mono WAV, never mp3 — the reason the seam carries a content type
        // at all instead of the controller hardcoding one.
        $this->assertSame('audio/wav', $audio->contentType);
    }

    /**
     * `pocket-tts serve` loads ONE language per process (`--language`), so a bilingual app needs one
     * server per locale. Routing English at the French server would not error — it would read English
     * words with a French model, which is the failure that sounds like a bug nobody can place.
     */
    public function testEachLocaleReachesItsOwnServer(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(self::AUDIO);
        });

        $client = $this->client($http);
        $client->synthesize('Vidange enregistrée.', Locale::Fr);
        $client->synthesize('Oil change logged.', Locale::En);

        $this->assertSame(['http://pocket-tts-fr:8000/tts', 'http://pocket-tts-en:8000/tts'], $urls);
    }

    public function testTheTextRidesTheMultipartBodyTheServerExpects(): void
    {
        $body = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = self::drain($options['body'] ?? '');

            return new MockResponse(self::AUDIO);
        });

        $this->client($http)->synthesize('Vidange enregistrée.', Locale::Fr);

        $this->assertStringContainsString('name="text"', (string) $body);
        $this->assertStringContainsString('Vidange enregistrée.', (string) $body);
    }

    /**
     * An unconfigured locale is silence, not a 500 — the notice card is already on screen. This is the
     * shape a French-only deployment takes: one server, and English simply does not speak.
     */
    public function testALocaleWithNoServerIsUnavailableAndMakesNoCall(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::AUDIO);
        });

        $client = new PocketTtsSpeechClient($http, ['fr' => 'http://pocket-tts-fr:8000', 'en' => '']);

        $this->expectException(SpeechUnavailable::class);

        try {
            $client->synthesize('Oil change logged.', Locale::En);
        } finally {
            $this->assertSame(0, $calls, 'An unconfigured locale must not reach any server.');
        }
    }

    public function testAServerErrorIsUnavailableNeverAnException(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->expectException(SpeechUnavailable::class);

        $this->client($http)->synthesize('Vidange enregistrée.', Locale::Fr);
    }

    public function testAnUnreachableServerIsUnavailable(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused.');
        });

        $this->expectException(SpeechUnavailable::class);

        $this->client($http)->synthesize('Vidange enregistrée.', Locale::Fr);
    }

    /**
     * `pocket-tts serve` STREAMS the wav, so it writes a 2 GB placeholder into the RIFF and data
     * chunk sizes rather than the real byte count. An <audio> element believes that header: it reads
     * a 6-second line as an 11-hour stream, and when playback reaches the actual EOF Firefox reports
     * MEDIA_ERR_ABORTED — which useVoice.ts treats as an autoplay refusal, putting the line back AND
     * latching the audition closed. One line with a wrong header silences every line after it.
     *
     * We buffer the whole body to return it, so the true length is known here and nowhere else.
     */
    public function testTheStreamedWavHeaderIsRewrittenToTheRealLength(): void
    {
        $pcm = str_repeat("\x01\x02", 512);
        $http = new MockHttpClient(new MockResponse(self::placeholderWav($pcm)));

        $bytes = $this->client($http)->synthesize('Vidange enregistrée.', Locale::Fr)->bytes;

        $this->assertSame(\strlen($bytes) - 8, unpack('V', substr($bytes, 4, 4))[1], 'RIFF size must be the real file size minus 8.');

        $data = strpos($bytes, 'data');
        $this->assertNotFalse($data);
        $this->assertSame(\strlen($pcm), unpack('V', substr($bytes, $data + 4, 4))[1], 'data size must be the real payload length.');
        $this->assertSame($pcm, substr($bytes, $data + 8), 'the samples themselves must be untouched.');
    }

    /** A body that is not RIFF/WAVE at all must pass through rather than be corrupted by a patch. */
    public function testANonWavBodyIsReturnedUntouched(): void
    {
        $http = new MockHttpClient(new MockResponse('not audio at all'));

        $this->assertSame('not audio at all', $this->client($http)->synthesize('x', Locale::Fr)->bytes);
    }

    /** 44-byte canonical header carrying the streaming placeholder pocket-tts actually emits. */
    private static function placeholderWav(string $pcm): string
    {
        return 'RIFF'.pack('V', 2000000036).'WAVE'
            .'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', 2000000000).$pcm;
    }

    /**
     * HttpClient normalises a multipart body into a closure that yields chunks until it returns an
     * empty string — not a string and not a Traversable.
     */
    private static function drain(mixed $body): string
    {
        if (\is_string($body)) {
            return $body;
        }

        if ($body instanceof \Closure) {
            $out = '';
            while ('' !== ($chunk = $body(8192)) && null !== $chunk) {
                $out .= $chunk;
            }

            return $out;
        }

        return implode('', iterator_to_array($body));
    }

    private function client(MockHttpClient $http): PocketTtsSpeechClient
    {
        return new PocketTtsSpeechClient($http, [
            'fr' => 'http://pocket-tts-fr:8000',
            'en' => 'http://pocket-tts-en:8000',
        ]);
    }
}
