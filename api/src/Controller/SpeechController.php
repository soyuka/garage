<?php

declare(strict_types=1);

namespace App\Controller;

use App\Capability\Capabilities;
use App\Capability\Capability;
use App\I18n\Locale;
use App\Security\OwnerRateLimit;
use App\Speech\SpeechClient;
use App\Speech\SpeechStream;
use App\Speech\SpeechUnavailable;
use App\Speech\SpeechVoice;
use App\Speech\Verbalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /tts — speak one line. The browser's only route to a voice.
 *
 * Authenticated wholesale by the `api` firewall (security.yaml `^/tts`), which is the point: an open
 * TTS proxy is somebody else's credits being spent, and the key it would spend them with lives here.
 * Unlike a bill download there is nothing owner-SCOPED to check — no stored resource is read — so the
 * gate is authentication, not ownership. Each owner gets SPEECH_RATE_LIMIT lines per sliding hour
 * (`limiter.speech`); a cached line still spends a slot.
 *
 * STREAMED, chunk for chunk, since 2026-09-05: the browser plays each piece as it lands (dashboard
 * `noyau/speaker.ts`). Buffering the body here made the first byte wait for the last one — 3.2 s for a
 * short line, 10 s for a medium one (docs/archive/pocket-tts.md) — which is the whole gap between the
 * card appearing and the voice starting.
 *
 * Without an ElevenLabs key ({@see Capability::Voice} off) both routes answer as if the vendor were
 * down, without calling it: 503 on `/tts`, an empty list on `/tts/voices`. The dashboard already knows
 * from GET /agent/link and never asks; these are the fallback for a client that does.
 */
final readonly class SpeechController
{
    /**
     * A toast is one line. Anything past this is an upstream bug — refuse it here rather than pay to
     * narrate a paragraph nobody will listen to.
     */
    private const int MAX_CHARS = 400;

    public function __construct(
        private SpeechClient $speech,
        private Verbalizer $verbalizer,
        private Capabilities $capabilities,
        private OwnerRateLimit $rateLimit,
        private RateLimiterFactoryInterface $speechLimiter,
        private RateLimiterFactoryInterface $speechGlobalLimiter,
    ) {
    }

    #[Route('/tts', name: 'speech_synthesize', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->capabilities->has(Capability::Voice)) {
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = json_decode($request->getContent(), true);
        $text = trim(\is_array($payload) && \is_string($payload['text'] ?? null) ? $payload['text'] : '');
        $requestedVoice = \is_array($payload) && \is_string($payload['voice'] ?? null) ? $payload['voice'] : null;

        if ('' === $text) {
            throw new BadRequestHttpException('Nothing to speak.');
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new BadRequestHttpException(\sprintf('A spoken line is capped at %d characters.', self::MAX_CHARS));
        }

        $this->rateLimit->consume($this->speechLimiter, 'Speech', $this->speechGlobalLimiter);

        try {
            // The browser announces its language on every request (framework.yaml
            // `set_locale_from_accept_language`), so the spoken line follows the interface without
            // the dashboard sending a field — the audition adds nothing to the wire (CONTRACT.md).
            $locale = Locale::resolve($request->getLocale());
            $voice = $this->resolveVoice($requestedVoice, $locale);
            // The card on screen keeps $text verbatim; only the voice gets the pronunciation pass
            // (docs/voice.md § Pronunciation layer) — « 250 347 km » read as one figure, not two.
            $spoken = $this->verbalizer->verbalize($text, $locale);
            $stream = $this->speech->stream($spoken, $locale, $voice);
        } catch (SpeechUnavailable) {
            // Silence is a degraded mode, never a broken one: the notice card is already on screen.
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new StreamedResponse(static fn () => self::send($stream), Response::HTTP_OK, [
            // Whatever the backend produced (ElevenLabs: streamed PCM in a WAV header).
            'Content-Type' => $stream->contentType,
            // The text is the cache key upstream, so the same sentence is byte-identical forever.
            'Cache-Control' => 'private, max-age=86400',
            // …per language. The same sentence read as French and as English is two different audios
            // on one URL, so the language has to be part of what a cache keys on.
            'Vary' => 'Accept-Language',
        ]);
    }

    /**
     * GET /tts/voices — the dashboard's picker source. Never gated by ownership like the bill
     * download (nothing stored is read), just by authentication, same as `/tts` itself.
     */
    #[Route('/tts/voices', name: 'speech_voices', methods: ['GET'])]
    public function voices(Request $request): Response
    {
        if (!$this->capabilities->has(Capability::Voice)) {
            return new JsonResponse([]);
        }

        $locale = Locale::resolve($request->getLocale());

        $voices = array_map(
            static fn (SpeechVoice $voice): array => ['id' => $voice->id, 'label' => $voice->label],
            $this->speech->voices($locale),
        );

        return new JsonResponse($voices, headers: [
            // The list rarely changes and is per-locale, same posture as CachingSpeechClient's own
            // 1h voices cache — no reason for the browser to re-fetch it on every render.
            // No manual `Vary: Accept-Language` here: `set_locale_from_accept_language` (framework.yaml)
            // already makes Symfony's ResponseListener append it to every response whose locale came
            // from the header (`_vary_by_language`) — setting it again here would only duplicate it.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * A stale or foreign voice id (a picker rendered before a vendor voice was retired, or one
     * pasted by hand) must never 400 the endpoint — it just falls back to the backend default,
     * exactly like requesting no voice at all.
     */
    private function resolveVoice(?string $requested, Locale $locale): ?string
    {
        if (null === $requested || '' === $requested) {
            return null;
        }

        foreach ($this->speech->voices($locale) as $voice) {
            if ($voice->id === $requested) {
                return $requested;
            }
        }

        return null;
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
            // @mago-expect lint:no-empty-catch-clause headers are out, nothing left to send
            // Headers are out and part of the line is already playing; a truncated stream is the
            // only honest ending. The card is on screen regardless.
        }
    }
}
