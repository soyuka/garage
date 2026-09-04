<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Locale;
use App\Speech\SpeechClient;
use App\Speech\SpeechUnavailable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            $audio = $this->speech->synthesize($text, Locale::resolve($request->getLocale()));
        } catch (SpeechUnavailable) {
            // Silence is a degraded mode, never a broken one: the notice card is already on screen.
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response($audio->bytes, Response::HTTP_OK, [
            // Whatever the backend produced: mp3 from ElevenLabs, WAV from a pocket-tts sidecar.
            'Content-Type' => $audio->contentType,
            // The text is the cache key upstream, so the same sentence is byte-identical forever.
            'Cache-Control' => 'private, max-age=86400',
            // …per language. The same sentence read as French and as English is two different audios
            // on one URL, so the language has to be part of what a cache keys on.
            'Vary' => 'Accept-Language',
        ]);
    }
}
