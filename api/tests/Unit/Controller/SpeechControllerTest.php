<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SpeechController;
use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpokenAudio;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The controller used to stamp `audio/mpeg` on every response, which was true only for as long as
 * ElevenLabs was the only backend. pocket-tts emits WAV, and a WAV body announced as mp3 is a body
 * the browser may refuse to decode — so the format travels WITH the bytes and the controller reports
 * whatever the backend produced.
 */
final class SpeechControllerTest extends TestCase
{
    public function testTheResponseAnnouncesTheFormatTheBackendActuallyProduced(): void
    {
        $response = $this->speak(new SpokenAudio('RIFFfake', 'audio/wav'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));
        $this->assertSame('RIFFfake', $response->getContent());
    }

    public function testAnMp3BackendStillAnnouncesMp3(): void
    {
        $response = $this->speak(new SpokenAudio('ID3fake', 'audio/mpeg'));

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
    }

    private function speak(SpokenAudio $audio): \Symfony\Component\HttpFoundation\Response
    {
        $speech = new class($audio) implements SpeechClient {
            public function __construct(private readonly SpokenAudio $audio)
            {
            }

            public function synthesize(string $text, Locale $locale): SpokenAudio
            {
                return $this->audio;
            }
        };

        return (new SpeechController($speech))(
            Request::create('/tts', 'POST', content: json_encode(['text' => 'Vidange enregistrée.']))
        );
    }
}
