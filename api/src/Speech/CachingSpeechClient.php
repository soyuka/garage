<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Psr\Log\LoggerInterface;

/**
 * Decorates {@see SpeechClient} with a disk cache (docs/voice-plan.md § Phase 2): a hit makes ZERO
 * calls to the wrapped backend. The Atelier repeats a handful of sentences constantly
 * (« Vidange enregistrée. », « J'ai pris la Mazda. »), so paying a vendor call — money on
 * ElevenLabs, ~3 s of CPU on pocket-tts — for the same line twice is pure waste.
 *
 * A miss is a TEE, not a buffer: the backend's chunks pass straight through to the caller with their
 * boundaries intact, and the cache is written once the stream has completed, from the body the
 * backend RETURNED (for pocket-tts, the header-stamped copy — not the wire concatenation). A stream
 * that dies part-way writes nothing: a half-line answering the same text forever would be worse than
 * a miss.
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

    public function stream(string $text, Locale $locale): SpeechStream
    {
        $key = hash('sha256', implode('|', [$this->backend, $this->model, $this->voice, $locale->value, $text]));
        $bytesPath = $this->path($key, 'bin');
        $typePath = $this->path($key, 'type');

        $cached = $this->read($bytesPath, $typePath);
        if (null !== $cached) {
            return SpeechStream::whole($cached);
        }

        $live = $this->inner->stream($text, $locale);

        return new SpeechStream($live->contentType, $this->tee($live, $bytesPath, $typePath));
    }

    /** @return \Generator<int, string, mixed, string> */
    private function tee(SpeechStream $live, string $bytesPath, string $typePath): \Generator
    {
        $body = yield from $live->chunks();

        $this->write($bytesPath, $typePath, new SpokenAudio($body, $live->contentType));

        return $body;
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
