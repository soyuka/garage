<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\DefaultVoices;
use PHPUnit\Framework\TestCase;

final class DefaultVoicesTest extends TestCase
{
    public function testEachLocaleGetsItsOwnDefault(): void
    {
        $voices = new DefaultVoices('fr-voice', 'en-voice');

        $this->assertSame('fr-voice', $voices->for(Locale::Fr));
        $this->assertSame('en-voice', $voices->for(Locale::En));
    }

    public function testAnEmptyEnglishDefaultFallsBackToTheDefaultVoice(): void
    {
        $voices = new DefaultVoices('fr-voice', '');

        $this->assertSame('fr-voice', $voices->for(Locale::En));
        $this->assertSame('fr-voice', $voices->for(Locale::Fr));
    }

    public function testTheEnglishDefaultIsOptional(): void
    {
        $this->assertSame('fr-voice', (new DefaultVoices('fr-voice'))->for(Locale::En));
    }
}
