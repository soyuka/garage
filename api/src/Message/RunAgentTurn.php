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
        // The call-to-action card the dashboard is currently showing (headline + finding + option
        // labels, compacted client-side). Carried so a terse reply to it ("la 2.0 16V", "oui") is
        // interpreted against the question rather than re-routed blind — turns stay stateless, this
        // is the one bounded breadcrumb, not a conversation history.
        public string $pendingContext = '',
    ) {
    }
}
