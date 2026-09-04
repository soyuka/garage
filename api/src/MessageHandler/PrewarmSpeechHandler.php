<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\I18n\Locale;
use App\Message\PrewarmSpeech;
use App\Speech\SpeechClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Synthesizes through the cached {@see SpeechClient} and discards the result — the only goal is
 * populating {@see \App\Speech\CachingSpeechClient}'s disk cache before the browser's own `POST
 * /tts` asks for the same line.
 *
 * Per the contract (dashboard/CHOREOGRAPHY.md § "The voice starts before the browser asks"): "A
 * prewarm is an optimisation and may never become a dependency." Any failure here — the vendor is
 * down, the cache directory is unwritable — is logged and swallowed, never rethrown: rethrowing
 * would hand it to Messenger's retry policy and eventually dead-letter a job whose only purpose was
 * to save the browser 2 seconds it can perfectly well spend itself on a miss.
 */
#[AsMessageHandler]
final readonly class PrewarmSpeechHandler
{
    public function __construct(
        private SpeechClient $speech,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PrewarmSpeech $message): void
    {
        try {
            $this->speech->synthesize($message->text, Locale::resolve($message->locale));
        } catch (\Throwable $e) {
            $this->logger->info('Speech prewarm failed (harmless — the browser will just take a miss on /tts): {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
