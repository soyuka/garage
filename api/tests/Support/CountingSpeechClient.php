<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpokenAudio;

final class CountingSpeechClient implements SpeechClient
{
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
}
