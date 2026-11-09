<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;

final class StreamingSpeechClient implements SpeechClient
{
    public int $calls = 0;

    /** @param list<string> $chunks what goes over the wire, in order */
    public function __construct(
        private readonly array $chunks,
        private readonly string $completed,
        private readonly bool $diesFirst = false,
    ) {
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
}
