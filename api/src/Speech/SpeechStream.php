<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * One spoken line IN FLIGHT: the format, and the body as the backend produces it.
 *
 * `pocket-tts serve` emits 80 ms of audio every ~60 ms starting ~250 ms after the request, while the
 * whole line takes 3–10 s to finish (docs/voice.md, 2026-09-05). Handing the browser the complete
 * body therefore delayed the first sound by the entire synthesis — that was the "text lands, voice
 * arrives seconds later" gap. This carries the chunks through instead, boundaries intact, so the
 * first one is on the wire while the last is still being computed.
 *
 * The generator's RETURN is the completed body — for pocket-tts the copy with real RIFF lengths
 * stamped in, which the wire chunks cannot have because the length does not exist until the end.
 * {@see CachingSpeechClient} stores that return, never the concatenated wire. Drained once: a
 * generator is a cursor, not a value, and {@see SpokenAudio} is the at-rest twin for anything that
 * needs the bytes twice.
 */
final readonly class SpeechStream
{
    /**
     * @param \Generator<int, string, mixed, string> $chunks yields body pieces in arrival order;
     *                                                       returns the complete body once done
     */
    public function __construct(
        public string $contentType,
        private \Generator $chunks,
    ) {
    }

    /** A body already at rest — a cache hit, or a backend with no streaming path. One chunk. */
    public static function whole(SpokenAudio $audio): self
    {
        return new self($audio->contentType, (static function () use ($audio): \Generator {
            yield $audio->bytes;

            return $audio->bytes;
        })());
    }

    /** @return \Generator<int, string, mixed, string> */
    public function chunks(): \Generator
    {
        return $this->chunks;
    }
}
