<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\I18n\Locale;
use App\Message\PrewarmSpeech;
use App\MessageHandler\PrewarmSpeechHandler;
use App\Speech\SpeechClient;
use App\Speech\SpeechUnavailable;
use App\Speech\SpokenAudio;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PrewarmSpeechHandlerTest extends TestCase
{
    public function testItSynthesizesThroughTheCachedClientForThatLocale(): void
    {
        $spy = new class implements SpeechClient {
            public ?string $text = null;
            public ?Locale $locale = null;

            public function synthesize(string $text, Locale $locale): SpokenAudio
            {
                $this->text = $text;
                $this->locale = $locale;

                return new SpokenAudio('bytes', 'audio/wav');
            }
        };

        $handler = new PrewarmSpeechHandler($spy, new NullLogger());
        $handler(new PrewarmSpeech('Vidange enregistrée.', 'fr'));

        $this->assertSame('Vidange enregistrée.', $spy->text);
        $this->assertSame(Locale::Fr, $spy->locale);
    }

    /**
     * Per the contract, a prewarm is an optimisation and may never become a dependency: a vendor
     * failure inside the handler must not bubble to Messenger, or a down backend would dead-letter
     * (or endlessly retry) a job whose only purpose was to save the browser 2 seconds.
     */
    public function testASynthesisFailureInsideTheHandlerDoesNotBubble(): void
    {
        $speech = new class implements SpeechClient {
            public function synthesize(string $text, Locale $locale): SpokenAudio
            {
                throw new SpeechUnavailable('pocket-tts could not be reached.');
            }
        };

        $handler = new PrewarmSpeechHandler($speech, new NullLogger());

        $handler(new PrewarmSpeech('Vidange enregistrée.', 'fr'));

        $this->addToAssertionCount(1);
    }
}
