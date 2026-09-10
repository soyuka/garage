<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\CachingSpeechClient;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;
use App\Speech\SpeechVoice;
use App\Speech\SpokenAudio;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * docs/voice-plan.md § Phase 2: a hit must make ZERO calls to the wrapped backend, and locale must
 * be part of what the cache keys on — the same sentence read as French and as English is two
 * different audios, and a key without locale would serve one for the other.
 */
final class CachingSpeechClientTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/tts-cache-test-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testASecondIdenticalRequestMakesZeroCallsToTheBackend(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner);

        $first = self::body($cache->stream('Vidange enregistrée.', Locale::Fr));
        $second = self::body($cache->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(1, $inner->calls, 'The second identical request must not reach the backend.');
        $this->assertSame('wav-bytes', $second);
        $this->assertSame($first, $second);
    }

    /**
     * The bug the plan calls out by name: a key without locale would serve the French audio back
     * for the English request of the exact same text, or vice versa.
     */
    public function testTheSameTextInADifferentLocaleIsAMiss(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner);

        self::body($cache->stream('Oil change logged.', Locale::Fr));
        self::body($cache->stream('Oil change logged.', Locale::En));

        $this->assertSame(2, $inner->calls, 'A different locale for the same text must not be served from the French entry.');
    }

    /**
     * {@see SpokenAudio} carries the content type alongside the bytes because it is a property of
     * the backend (mp3 vs wav) — the cache must not lose it and fall back to some default.
     */
    public function testTheContentTypeSurvivesARoundTripThroughTheCache(): void
    {
        $inner = $this->countingClient(new SpokenAudio('ID3fake', 'audio/mpeg'));
        $cache = $this->client($inner);

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr));
        $cached = $cache->stream('Vidange enregistrée.', Locale::Fr);

        $this->assertSame(1, $inner->calls);
        $this->assertSame('audio/mpeg', $cached->contentType);
    }

    /**
     * `backend|model|voice` are in the key alongside locale/text: a config change (swap model,
     * upgrade the ElevenLabs voice) must not let stale audio answer for text that also existed
     * before the change.
     */
    public function testADifferentVoiceForTheSameTextAndLocaleIsAMiss(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $a = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', 'voice-a', $this->cacheDir, new NullLogger());
        $b = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', 'voice-b', $this->cacheDir, new NullLogger());

        self::body($a->stream('Vidange enregistrée.', Locale::Fr));
        self::body($b->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(2, $inner->calls, 'A different voice must not share the other voice\'s cache entry.');
    }

    /**
     * A miss is a TEE, not a buffer: the backend's chunks pass through to the caller with their
     * boundaries intact — the browser starts playing on the first one — and what gets cached is the
     * body the backend RETURNED once it was complete, which for pocket-tts is the header-stamped copy
     * and not merely the concatenation of what went over the wire.
     */
    public function testAMissPassesTheChunksThroughAndCachesTheCompletedBody(): void
    {
        $inner = $this->streamingClient(['RIFF-placeholder', 'pcm-1', 'pcm-2'], 'RIFF-stamped pcm-1pcm-2');
        $cache = $this->client($inner);

        $first = iterator_to_array($cache->stream('Vidange enregistrée.', Locale::Fr)->chunks(), false);
        $second = iterator_to_array($cache->stream('Vidange enregistrée.', Locale::Fr)->chunks(), false);

        $this->assertSame(['RIFF-placeholder', 'pcm-1', 'pcm-2'], $first, 'The wire chunks must pass through untouched.');
        $this->assertSame(1, $inner->calls);
        $this->assertSame(['RIFF-stamped pcm-1pcm-2'], $second, 'The hit serves the completed body the backend returned.');
    }

    /** A backend that dies mid-line must not leave a half-line in the cache answering forever. */
    public function testABackendFailingMidStreamCachesNothing(): void
    {
        $inner = $this->streamingClient(['RIFF-placeholder', 'pcm-1'], 'RIFF-stamped pcm-1', diesFirst: true);
        $cache = $this->client($inner);

        try {
            iterator_to_array($cache->stream('Vidange enregistrée.', Locale::Fr)->chunks(), false);
            $this->fail('The failure must reach the caller.');
        } catch (SpeechUnavailable) {
        }

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(2, $inner->calls, 'The truncated line must not have been cached.');
    }

    /**
     * The REQUESTED voice, not just the configured default, must be in the key — a `voice` picked
     * on the dashboard must not be served the default voice's cached audio for the same line.
     */
    public function testARequestedVoiceIsADifferentCacheEntryFromTheConfiguredDefault(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner); // configured default voice is 'test-voice-id'

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr));
        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));

        $this->assertSame(2, $inner->calls, 'A requested voice must not share the default voice\'s cache entry.');
    }

    public function testTheSameRequestedVoiceIsACacheHit(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner);

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));
        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));

        $this->assertSame(1, $inner->calls, 'The second identical request for the same voice must not reach the backend.');
    }

    public function testVoicesDelegatesToTheInnerClientAndCachesTheResult(): void
    {
        $inner = $this->voicesCountingClient([new SpeechVoice('v1', 'Claire')]);
        $cache = $this->client($inner);

        $first = $cache->voices(Locale::Fr);
        $second = $cache->voices(Locale::Fr);

        $this->assertEquals([new SpeechVoice('v1', 'Claire')], $first);
        $this->assertEquals($first, $second);
        $this->assertSame(1, $inner->voicesCalls, 'The second call must be served from the cache, not the backend.');
    }

    public function testVoicesAreCachedPerLocale(): void
    {
        $inner = $this->voicesCountingClient([new SpeechVoice('v1', 'Claire')]);
        $cache = $this->client($inner);

        $cache->voices(Locale::Fr);
        $cache->voices(Locale::En);

        $this->assertSame(2, $inner->voicesCalls, 'A different locale must not be served from the other locale\'s cached file.');
    }

    /**
     * The bug this guards against: `TTS_BACKEND=pockettts` must never serve the voice list cached by
     * `TTS_BACKEND=elevenlabs` merely because both share the same on-disk cache directory. Switching
     * backends must be a cache miss, the same way switching locale already is.
     */
    public function testVoicesAreCachedPerBackend(): void
    {
        $inner = $this->voicesCountingClient([new SpeechVoice('v1', 'Claire')]);
        $elevenlabs = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', 'test-voice-id', $this->cacheDir, new NullLogger());
        $pockettts = new CachingSpeechClient($inner, 'pockettts', 'kyutai-pocket-tts', '', $this->cacheDir, new NullLogger());

        $elevenlabs->voices(Locale::Fr);
        $pockettts->voices(Locale::Fr);

        $this->assertSame(2, $inner->voicesCalls, 'Switching TTS_BACKEND must not serve the other backend\'s cached voice list.');
    }

    /** @return SpeechClient&object{voicesCalls: int} */
    private function voicesCountingClient(array $voices): SpeechClient
    {
        return new class($voices) implements SpeechClient {
            public int $voicesCalls = 0;

            /** @param list<SpeechVoice> $voices */
            public function __construct(private readonly array $voices)
            {
            }

            public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
            {
                throw new \LogicException('Not exercised by the voices() tests.');
            }

            public function voices(Locale $locale): array
            {
                ++$this->voicesCalls;

                return $this->voices;
            }
        };
    }

    private function client(SpeechClient $inner): CachingSpeechClient
    {
        return new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', 'test-voice-id', $this->cacheDir, new NullLogger());
    }

    private static function body(SpeechStream $stream): string
    {
        return implode('', iterator_to_array($stream->chunks(), false));
    }

    /** @return SpeechClient&object{calls: int} */
    private function countingClient(SpokenAudio $audio): SpeechClient
    {
        return new class($audio) implements SpeechClient {
            public int $calls = 0;

            public function __construct(private readonly SpokenAudio $audio)
            {
            }

            public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
            {
                ++$this->calls;

                return SpeechStream::whole($this->audio);
            }

            public function voices(Locale $locale): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<string> $chunks    what goes over the wire, in order
     * @param string       $completed the body the backend returns once done
     * @param bool         $diesFirst the FIRST call dies after its chunks instead of completing
     *
     * @return SpeechClient&object{calls: int}
     */
    private function streamingClient(array $chunks, string $completed, bool $diesFirst = false): SpeechClient
    {
        return new class($chunks, $completed, $diesFirst) implements SpeechClient {
            public int $calls = 0;

            public function __construct(private readonly array $chunks, private readonly string $completed, private readonly bool $diesFirst)
            {
            }

            public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
            {
                $call = ++$this->calls;

                return new SpeechStream('audio/wav', (function () use ($call): \Generator {
                    yield from $this->chunks;

                    if (1 === $call && $this->diesFirst) {
                        throw new SpeechUnavailable('pocket-tts dropped the stream.');
                    }

                    return $this->completed;
                })());
            }

            public function voices(Locale $locale): array
            {
                return [];
            }
        };
    }
}
