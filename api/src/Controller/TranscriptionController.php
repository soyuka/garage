<?php

declare(strict_types=1);

namespace App\Controller;

use App\Capability\Capabilities;
use App\Capability\Capability;
use App\I18n\Locale;
use App\Security\OwnerRateLimit;
use App\Transcription\GarageVocabulary;
use App\Transcription\TranscriptionClient;
use App\Transcription\TranscriptionUnavailable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /stt — transcribe one dictated line. The browser's only route to a transcript.
 *
 * The input twin of {@see SpeechController}, gated the same way and for a sharper reason: an open
 * transcription proxy is somebody else's credits being spent. Unlike `/tts` it DOES read something
 * owner-scoped — {@see GarageVocabulary} biases the transcriber with this owner's own vehicle names —
 * so the authentication gate (security.yaml `^/stt`) is also what keeps one tenant's garage out of
 * another's request. That was not true when this endpoint was first written; the bias list made it so.
 *
 * What comes back is the operator's words VERBATIM. There is no `Verbalizer` on this side and there
 * must not be one: the pronunciation layer exists because the EAR needs symbols and digit groups
 * rewritten, whereas the agent reads a dictated sentence exactly as it reads a typed one. Nothing
 * here rephrases, cleans up, or guesses at what was meant — the operator reads the transcript in the
 * composer and corrects it themselves before it becomes a turn.
 */
final readonly class TranscriptionController
{
    /**
     * An instruction is one line. 30 s of opus is ~90 KB, so a body past this is a thumb resting on
     * the control, not a sentence — refuse it here rather than heat the CPU transcribing a pocket.
     */
    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private TranscriptionClient $transcription,
        private GarageVocabulary $vocabulary,
        private LoggerInterface $logger,
        private Capabilities $capabilities,
        private OwnerRateLimit $rateLimit,
        private RateLimiterFactoryInterface $transcriptionLimiter,
    ) {
    }

    #[Route('/stt', name: 'speech_transcribe', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // No key: dictation is off, not failing. Same silent 503 as a vendor outage, minus the call.
        if (!$this->capabilities->has(Capability::Dictation)) {
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $audio = $request->getContent();
        $contentType = $request->headers->get('Content-Type') ?? '';

        if ('' === $audio) {
            throw new BadRequestHttpException('Nothing to transcribe.');
        }

        if (\strlen($audio) > self::MAX_BYTES) {
            throw new BadRequestHttpException(\sprintf('A dictated line is capped at %d bytes.', self::MAX_BYTES));
        }

        // The composer only ever posts what MediaRecorder produced. A text body handed to a speech
        // model does not fail — it comes back as confident nonsense, which is the worse outcome.
        if (!str_starts_with($contentType, 'audio/')) {
            throw new BadRequestHttpException('A recording must be posted as audio/*.');
        }

        $this->rateLimit->consume($this->transcriptionLimiter, 'Transcription');

        try {
            // The browser announces its language on every request (framework.yaml
            // `set_locale_from_accept_language`), so dictation follows the interface without the
            // dashboard sending a field — the wire gains nothing (CONTRACT.md).
            $locale = Locale::resolve($request->getLocale());
            $text = $this->transcription->transcribe($audio, $contentType, $locale, $this->vocabulary->terms($locale));
        } catch (TranscriptionUnavailable $e) {
            // Silent to the operator, never silent to the logs. Every failure here looks IDENTICAL
            // from the dashboard — a mic that records and then fills nothing in — so without this line
            // a malformed parameter, an unentitled key and a vendor outage are indistinguishable. Both
            // of the first two were live on 2026-09-11 and the only symptom was a 503; the cause had
            // to be found by re-probing the vendor by hand. The client puts the vendor's own sentence
            // in the message ({@see \App\Transcription\ElevenLabsTranscriptionClient}).
            $this->logger->notice('A dictated line could not be transcribed: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            // Silence is a degraded mode: the composer keeps what it had and the operator types.
            return new Response(status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // '' is a 200, not an error: the endpoint worked, the recording simply held no speech.
        return new JsonResponse(['text' => $text]);
    }
}
