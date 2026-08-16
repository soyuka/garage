<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;
use Psr\Container\ContainerInterface;

/**
 * The {@see SpeechClient} the controller actually depends on: a thin router picking the backend at
 * runtime from {@see SpeechBackend} (env TTS_BACKEND). Switching provider is a one-line env edit plus
 * a worker restart — no recompile, no service re-aliasing.
 *
 * The clients sit behind a service locator so only the selected backend is ever instantiated; the
 * unused provider never demands its API key. Directly mirrors
 * {@see \App\Agent\WebSearch\SelectableWebSearchClient} — one shape for provider seams in this app.
 */
final readonly class SelectableSpeechClient implements SpeechClient
{
    public function __construct(
        private ContainerInterface $clients,
        private SpeechBackend $backend,
    ) {
    }

    public function synthesize(string $text, Locale $locale): string
    {
        return $this->client()->synthesize($text, $locale);
    }

    private function client(): SpeechClient
    {
        return $this->clients->get($this->backend->value);
    }
}
