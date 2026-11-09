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
 * Key: `sha256(backend|model|voice|settings|locale|text)`. `locale` MUST be in it: the same sentence read as
 * French and as English is two different audios, and a key without locale would serve one for the
 * other. `backend|model|voice|settings` guards the other direction — swap the model, upgrade the
 * ElevenLabs voice, or change its speed and the old audio must not keep answering for text that also
 * existed under the old config.
 *
 * Wraps the backend directly (config/services.yaml `App\Speech\SpeechClient`), with the model/voice
 * identifying the coming audio passed in, so the decorator itself never has to know which backend
 * it fronts.
 *
 * Storage: two sibling files per key, `<hash>.bin` (raw bytes) and `<hash>.type` (the content type).
 * A sidecar rather than an inferred extension: {@see SpokenAudio} already carries whatever content
 * type the backend produced (today ElevenLabs PCM in a WAV header), and a sidecar round-trips it
 * exactly with no MIME-to-extension table to keep in sync as backends change.
 *
 * Best-effort like the rest of the voice path: a cache directory that cannot be created or written
 * degrades to "always call the backend", never to a broken turn.
 */
final readonly class CachingSpeechClient implements SpeechClient
{
    private const int VOICES_TTL_SECONDS = 3600;

    public function __construct(
        private SpeechClient $inner,
        private string $backend,
        private string $model,
        private string $voice,
        private string $cacheDir,
        private LoggerInterface $logger,
        private float $speed = 1.0,
    ) {
    }

    public function stream(string $text, Locale $locale, ?string $voice = null): SpeechStream
    {
        // The REQUESTED voice, not just the configured default: a line spoken with voice A and the
        // same line with voice B must be two different cache entries, or picking a voice on the
        // dashboard would keep hearing whichever voice happened to be cached first.
        $resolvedVoice = $voice ?? $this->voice;
        $key = hash('sha256', implode('|', [$this->backend, $this->model, $resolvedVoice, $this->settingsKey(), $locale->value, $text]));
        $bytesPath = $this->path($key, 'bin');
        $typePath = $this->path($key, 'type');

        $cached = $this->read($bytesPath, $typePath);
        if (null !== $cached) {
            return SpeechStream::whole($cached);
        }

        $live = $this->inner->stream($text, $locale, $voice);

        return new SpeechStream($live->contentType, $this->tee($live, $bytesPath, $typePath));
    }

    /**
     * Cached for an hour, same disk directory, `.voices.<sha256(backend|model|voice|locale)>.json` —
     * a vendor voice list changes rarely and is not worth a call on every picker render. Backend is in
     * the key, not just locale: swapping the backend must never serve the other backend's list off
     * the same cache directory. Best-effort like the rest of this class: a cache miss on write just
     * means the next call pays for a fresh vendor round trip.
     */
    public function voices(Locale $locale): array
    {
        $path = $this->voicesPath($locale);

        $cached = $this->readVoices($path);
        if (null !== $cached) {
            return $cached;
        }

        $voices = $this->inner->voices($locale);
        $this->writeVoices($path, $voices);

        return $voices;
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

    private function settingsKey(): string
    {
        return json_encode(['speed' => $this->speed], \JSON_THROW_ON_ERROR);
    }

    /**
     * Keyed on backend|model|voice|locale. Speed is deliberately absent: it changes generated audio,
     * not the list of voices. Locale alone is not enough, or swapping the backend would keep
     * serving the previously configured backend's voice list under the same cache directory.
     */
    private function voicesPath(Locale $locale): string
    {
        $key = hash('sha256', implode('|', [$this->backend, $this->model, $this->voice, $locale->value]));

        return rtrim($this->cacheDir, '/').'/.voices.'.$key.'.json';
    }

    /** @return ?list<SpeechVoice> null on a miss, an expired entry, or anything that fails to parse */
    private function readVoices(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $decoded = false !== $raw ? json_decode($raw, true) : null;
        if (!\is_array($decoded) || !\is_int($decoded['at'] ?? null) || !\is_array($decoded['voices'] ?? null)) {
            return null;
        }

        if (time() - $decoded['at'] > self::VOICES_TTL_SECONDS) {
            return null;
        }

        $voices = [];
        foreach ($decoded['voices'] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || !\is_string($entry['label'] ?? null)) {
                return null;
            }
            $voices[] = new SpeechVoice($entry['id'], $entry['label']);
        }

        return $voices;
    }

    /** @param list<SpeechVoice> $voices */
    private function writeVoices(string $path, array $voices): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            $this->logger->warning('TTS cache directory {dir} could not be created; voices will not be cached.', ['dir' => $this->cacheDir]);

            return;
        }

        $payload = json_encode([
            'at' => time(),
            'voices' => array_map(static fn (SpeechVoice $v): array => ['id' => $v->id, 'label' => $v->label], $voices),
        ]);

        if (false === $payload || false === @file_put_contents($path, $payload)) {
            $this->logger->warning('TTS voices cache write failed for {path}; this list will not be cached.', ['path' => $path]);
        }
    }
}
