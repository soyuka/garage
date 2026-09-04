<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Psr\Log\LoggerInterface;

/**
 * Decorates {@see SpeechClient} with a disk cache (docs/voice-plan.md § Phase 2): a hit makes ZERO
 * calls to the wrapped backend. The Atelier repeats a handful of sentences constantly
 * (« Vidange enregistrée. », « J'ai pris la Mazda. »), so paying a vendor call — money on
 * ElevenLabs, a ~2s wait on pocket-tts — for the same line twice is pure waste.
 *
 * Key: `sha256(backend|model|voice|locale|text)`. `locale` MUST be in it: the same sentence read as
 * French and as English is two different audios, and a key without locale would serve one for the
 * other. `backend|model|voice` guards the other direction — swap the model or upgrade the ElevenLabs
 * voice and the old audio must not keep answering for text that also existed under the old config.
 *
 * Wraps each backend INDIVIDUALLY (config/services.yaml `speech.caching.*`), not the routed
 * {@see SelectableSpeechClient}: only at that point is the model/voice identifying the coming audio
 * a compile-time value, so the decorator itself never has to know which backend is live.
 *
 * Storage: two sibling files per key, `<hash>.bin` (raw bytes) and `<hash>.type` (the content type).
 * A sidecar rather than an inferred extension: {@see SpokenAudio} already carries whatever content
 * type the backend produced (mp3 from ElevenLabs, wav from pocket-tts), and a sidecar round-trips it
 * exactly with no MIME-to-extension table to keep in sync as backends change.
 *
 * Best-effort like the rest of the voice path: a cache directory that cannot be created or written
 * degrades to "always call the backend", never to a broken turn.
 */
final readonly class CachingSpeechClient implements SpeechClient
{
    public function __construct(
        private SpeechClient $inner,
        private string $backend,
        private string $model,
        private string $voice,
        private string $cacheDir,
        private LoggerInterface $logger,
    ) {
    }

    public function synthesize(string $text, Locale $locale): SpokenAudio
    {
        $key = hash('sha256', implode('|', [$this->backend, $this->model, $this->voice, $locale->value, $text]));
        $bytesPath = $this->path($key, 'bin');
        $typePath = $this->path($key, 'type');

        $cached = $this->read($bytesPath, $typePath);
        if (null !== $cached) {
            return $cached;
        }

        $audio = $this->inner->synthesize($text, $locale);
        $this->write($bytesPath, $typePath, $audio);

        return $audio;
    }

    private function read(string $bytesPath, string $typePath): ?SpokenAudio
    {
        if (!is_file($bytesPath) || !is_file($typePath)) {
            return null;
        }

        $bytes = file_get_contents($bytesPath);
        $type = file_get_contents($typePath);
        if (false === $bytes || false === $type) {
            return null;
        }

        return new SpokenAudio($bytes, $type);
    }

    private function write(string $bytesPath, string $typePath, SpokenAudio $audio): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            $this->logger->warning('TTS cache directory {dir} could not be created; this line will not be cached.', ['dir' => $this->cacheDir]);

            return;
        }

        if (false === @file_put_contents($bytesPath, $audio->bytes) || false === @file_put_contents($typePath, $audio->contentType)) {
            $this->logger->warning('TTS cache write failed for {path}; this line will not be cached.', ['path' => $bytesPath]);
        }
    }

    private function path(string $key, string $extension): string
    {
        return rtrim($this->cacheDir, '/').'/'.$key.'.'.$extension;
    }
}
