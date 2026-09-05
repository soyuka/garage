<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /tts — speak one line. The browser's only route to a voice.
 *
 * Authenticated wholesale by the `api` firewall (security.yaml `^/tts`), which is the point: an open
 * TTS proxy is somebody else's credits being spent, and the key it would spend them with lives here.
 * Unlike a bill download there is nothing owner-SCOPED to check — no stored resource is read — so the
 * gate is authentication, not ownership. Per-owner budget accounting arrives with the cache layer
 * (docs/voice-plan.md § Phase 2).
 *
 * STREAMED, chunk for chunk, since 2026-09-05. pocket-tts produces the first 80 ms of audio ~250 ms
 * after the request and the rest at ~0.77× real time; the browser plays each piece as it lands
 * (dashboard `noyau/speaker.ts`). Buffering the body here made the first byte wait for the last one —
 * 3.2 s for a short line, 10 s for a medium one (docs/voice.md) — which is the whole gap between the
 * card appearing and the voice starting.
 */
final class SpeechController
{
    /**
     * A toast is one line. Anything past this is an upstream bug — refuse it here rather than pay to
     * narrate a paragraph nobody will listen to.
     */
    private const int MAX_CHARS = 400;

    public function __construct(private readonly SpeechClient $speech)
    {
    }

    #[Route('/tts', name: 'speech_synthesize', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $text = trim(\is_array($payload) && \is_string($payload['text'] ?? null) ? $payload['text'] : '');

        if ('' === $text) {
            throw new BadRequestHttpException('Nothing to speak.');
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new BadRequestHttpException(\sprintf('A spoken line is capped at %d characters.', self::MAX_CHARS));
        }

        try {
            // The browser announces its language on every request (framework.yaml
            // `set_locale_from_accept_language`), so the spoken line follows the interface without
            // the dashboard sending a field — the audition adds nothing to the wire (CONTRACT.md).
            $stream = $this->speech->stream($text, Locale::resolve($request->getLocale()));
        } catch (SpeechUnavailable) {
            // Silence is a degraded mode, never a broken one: the notice card is already on screen.
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new StreamedResponse(static fn () => self::send($stream), Response::HTTP_OK, [
            // Whatever the backend produced: mp3 from ElevenLabs, WAV from a pocket-tts sidecar.
            'Content-Type' => $stream->contentType,
            // The text is the cache key upstream, so the same sentence is byte-identical forever.
            'Cache-Control' => 'private, max-age=86400',
            // …per language. The same sentence read as French and as English is two different audios
            // on one URL, so the language has to be part of what a cache keys on.
            'Vary' => 'Accept-Language',
        ]);
    }

    private static function send(SpeechStream $stream): void
    {
        try {
            foreach ($stream->chunks() as $chunk) {
                echo $chunk;
                // php.ini `output_buffering=4096` would otherwise hold a chunk until the next one
                // overflows it: the user-level buffer has to be pushed before the SAPI is.
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        } catch (SpeechUnavailable) {
            // Headers are out and part of the line is already playing; a truncated stream is the
            // only honest ending. The card is on screen regardless.
        }
    }
}
