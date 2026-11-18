<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\CachingSpeechClient;
use App\Speech\DefaultVoices;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;
use App\Speech\SpeechVoice;
use App\Speech\SpokenAudio;
use App\Tests\Support\CountingSpeechClient;
use App\Tests\Support\StreamingSpeechClient;
use App\Tests\Support\VoicesCountingSpeechClient;
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
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
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
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
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
        $inner = new CountingSpeechClient(new SpokenAudio('ID3fake', 'audio/mpeg'));
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
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $a = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('voice-a'), $this->cacheDir, new NullLogger());
        $b = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('voice-b'), $this->cacheDir, new NullLogger());

        self::body($a->stream('Vidange enregistrée.', Locale::Fr));
        self::body($b->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(2, $inner->calls, 'A different voice must not share the other voice\'s cache entry.');
    }

    public function testADifferentSpeedForTheSameTextAndVoiceIsAMiss(): void
    {
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $natural = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_v3', new DefaultVoices('voice-a'), $this->cacheDir, new NullLogger(), speed: 1.0);
        $faster = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_v3', new DefaultVoices('voice-a'), $this->cacheDir, new NullLogger(), speed: 1.05);

        self::body($natural->stream('Vidange enregistrée.', Locale::Fr));
        self::body($faster->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(2, $inner->calls, 'A different speed must not serve stale audio generated at another pace.');
    }

    /**
     * A miss is a TEE, not a buffer: the backend's chunks pass through to the caller with their
     * boundaries intact — the browser starts playing on the first one — and what gets cached is the
     * body the backend RETURNED once it was complete, which for pocket-tts is the header-stamped copy
     * and not merely the concatenation of what went over the wire.
     */
    public function testAMissPassesTheChunksThroughAndCachesTheCompletedBody(): void
    {
        $inner = new StreamingSpeechClient(['RIFF-placeholder', 'pcm-1', 'pcm-2'], 'RIFF-stamped pcm-1pcm-2');
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
        $inner = new StreamingSpeechClient(['RIFF-placeholder', 'pcm-1'], 'RIFF-stamped pcm-1', diesFirst: true);
        $cache = $this->client($inner);

        try {
            iterator_to_array($cache->stream('Vidange enregistrée.', Locale::Fr)->chunks(), false);
            $this->fail('The failure must reach the caller.');
            // @mago-expect lint:no-empty-catch-clause the failure is expected; the test asserts the backend is called again
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
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner); // configured default voice is 'test-voice-id'

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr));
        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));

        $this->assertSame(2, $inner->calls, 'A requested voice must not share the default voice\'s cache entry.');
    }

    public function testTheSameRequestedVoiceIsACacheHit(): void
    {
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner);

        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));
        self::body($cache->stream('Vidange enregistrée.', Locale::Fr, 'other-voice-id'));

        $this->assertSame(1, $inner->calls, 'The second identical request for the same voice must not reach the backend.');
    }

    public function testVoicesDelegatesToTheInnerClientAndCachesTheResult(): void
    {
        $inner = new VoicesCountingSpeechClient([new SpeechVoice('v1', 'Claire')]);
        $cache = $this->client($inner);

        $first = $cache->voices(Locale::Fr);
        $second = $cache->voices(Locale::Fr);

        $this->assertEquals([new SpeechVoice('v1', 'Claire')], $first);
        $this->assertEquals($first, $second);
        $this->assertSame(1, $inner->voicesCalls, 'The second call must be served from the cache, not the backend.');
    }

    public function testVoicesAreCachedPerLocale(): void
    {
        $inner = new VoicesCountingSpeechClient([new SpeechVoice('v1', 'Claire')]);
        $cache = $this->client($inner);

        $cache->voices(Locale::Fr);
        $cache->voices(Locale::En);

        $this->assertSame(2, $inner->voicesCalls, 'A different locale must not be served from the other locale\'s cached file.');
    }

    /**
     * The bug this guards against: a second backend must never serve the voice list cached by
     * ElevenLabs merely because both share the same on-disk cache directory. Switching
     * backends must be a cache miss, the same way switching locale already is.
     */
    public function testVoicesAreCachedPerBackend(): void
    {
        $inner = new VoicesCountingSpeechClient([new SpeechVoice('v1', 'Claire')]);
        $elevenlabs = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('test-voice-id'), $this->cacheDir, new NullLogger());
        $other = new CachingSpeechClient($inner, 'other-backend', 'other-model', new DefaultVoices(''), $this->cacheDir, new NullLogger());

        $elevenlabs->voices(Locale::Fr);
        $other->voices(Locale::Fr);

        $this->assertSame(2, $inner->voicesCalls, 'Switching backends must not serve the other backend\'s cached voice list.');
    }

    public function testChangingTheEnglishDefaultVoiceIsAMissForEnglishOnly(): void
    {
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $before = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-old'), $this->cacheDir, new NullLogger());
        $after = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-new'), $this->cacheDir, new NullLogger());

        self::body($before->stream('Oil change logged.', Locale::En));
        self::body($after->stream('Oil change logged.', Locale::En));
        $this->assertSame(2, $inner->calls, 'A new English default voice must not replay audio cached under the old one.');
    }

    public function testChangingTheEnglishDefaultVoiceKeepsTheFrenchEntries(): void
    {
        $inner = new CountingSpeechClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $before = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-old'), $this->cacheDir, new NullLogger());
        $after = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-new'), $this->cacheDir, new NullLogger());

        self::body($before->stream('Vidange enregistrée.', Locale::Fr));
        self::body($after->stream('Vidange enregistrée.', Locale::Fr));

        $this->assertSame(1, $inner->calls);
    }

    public function testChangingTheEnglishDefaultVoiceInvalidatesTheEnglishVoiceList(): void
    {
        $inner = new VoicesCountingSpeechClient([new SpeechVoice('v1', 'Claire')]);
        $before = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-old'), $this->cacheDir, new NullLogger());
        $after = new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('fr-voice', 'en-voice-new'), $this->cacheDir, new NullLogger());

        $before->voices(Locale::En);
        $after->voices(Locale::En);

        $this->assertSame(2, $inner->voicesCalls);
    }

    private function client(SpeechClient $inner): CachingSpeechClient
    {
        return new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', new DefaultVoices('test-voice-id'), $this->cacheDir, new NullLogger());
    }

    private static function body(SpeechStream $stream): string
    {
        return implode('', iterator_to_array($stream->chunks(), false));
    }
}
