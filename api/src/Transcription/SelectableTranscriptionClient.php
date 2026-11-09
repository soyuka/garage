<?php

declare(strict_types=1);

namespace App\Transcription;

use App\I18n\Locale;
use Psr\Container\ContainerInterface;

/**
 * The {@see TranscriptionClient} the controller depends on: a thin router picking the backend at
 * runtime from {@see TranscriptionBackend} (env `STT_BACKEND`). Switching is an env edit plus a
 * worker restart — no recompile, no service re-aliasing.
 *
 * Service locator so only the selected backend is instantiated, which is what lets an unused hosted
 * provider sit in the container without demanding its API key. Mirrors
 * {@see \App\Agent\WebSearch\SelectableWebSearchClient} — one shape for provider seams in this app.
 */
final readonly class SelectableTranscriptionClient implements TranscriptionClient
{
    public function __construct(
        private ContainerInterface $clients,
        private TranscriptionBackend $backend,
    ) {
    }

    public function transcribe(string $audio, string $contentType, Locale $locale, array $keyterms = []): string
    {
        $client = $this->clients->get($this->backend->value);
        if (!$client instanceof TranscriptionClient) {
            throw new \LogicException(\sprintf('The "%s" transcription backend is not a TranscriptionClient.', $this->backend->value));
        }

        return $client->transcribe($audio, $contentType, $locale, $keyterms);
    }
}
