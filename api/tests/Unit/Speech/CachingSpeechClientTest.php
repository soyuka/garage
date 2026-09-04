<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\CachingSpeechClient;
use App\Speech\SpeechClient;
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

        $first = $cache->synthesize('Vidange enregistrée.', Locale::Fr);
        $second = $cache->synthesize('Vidange enregistrée.', Locale::Fr);

        $this->assertSame(1, $inner->calls, 'The second identical request must not reach the backend.');
        $this->assertSame('wav-bytes', $second->bytes);
        $this->assertSame($first->bytes, $second->bytes);
    }

    /**
     * The bug the plan calls out by name: a key without locale would serve the French audio back
     * for the English request of the exact same text, or vice versa.
     */
    public function testTheSameTextInADifferentLocaleIsAMiss(): void
    {
        $inner = $this->countingClient(new SpokenAudio('wav-bytes', 'audio/wav'));
        $cache = $this->client($inner);

        $cache->synthesize('Oil change logged.', Locale::Fr);
        $cache->synthesize('Oil change logged.', Locale::En);

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

        $cache->synthesize('Vidange enregistrée.', Locale::Fr);
        $cached = $cache->synthesize('Vidange enregistrée.', Locale::Fr);

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

        $a->synthesize('Vidange enregistrée.', Locale::Fr);
        $b->synthesize('Vidange enregistrée.', Locale::Fr);

        $this->assertSame(2, $inner->calls, 'A different voice must not share the other voice\'s cache entry.');
    }

    private function client(SpeechClient $inner): CachingSpeechClient
    {
        return new CachingSpeechClient($inner, 'elevenlabs', 'eleven_flash_v2_5', 'test-voice-id', $this->cacheDir, new NullLogger());
    }

    /** @return SpeechClient&object{calls: int} */
    private function countingClient(SpokenAudio $audio): SpeechClient
    {
        return new class($audio) implements SpeechClient {
            public int $calls = 0;

            public function __construct(private readonly SpokenAudio $audio)
            {
            }

            public function synthesize(string $text, Locale $locale): SpokenAudio
            {
                ++$this->calls;

                return $this->audio;
            }
        };
    }
}
