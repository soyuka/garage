<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechVoice;

final class VoicesCountingSpeechClient implements SpeechClient
{
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
}
