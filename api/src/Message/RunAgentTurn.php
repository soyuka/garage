<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One agent turn, queued for the async worker. Carries the user instruction plus the dashboard
 * snapshot the HTTP request held, and the *absolute* per-session Mercure topic the render intents
 * must publish to — resolved in the controller (it needs the request scheme/host) so the worker,
 * which has no request, can bind it verbatim.
 */
final readonly class RunAgentTurn
{
    public function __construct(
        public string $instruction,
        public string $sessionTopic,
        public string $selectedId = '',
        public string $summary = '',
    ) {
    }
}
