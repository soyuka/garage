<?php

declare(strict_types=1);

namespace App\Message;

use App\I18n\Locale;

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
        // The authenticated owner (the Casdoor email claim, `app.owner_claim`) the turn runs as. Threaded from the controller
        // because the worker has no request and no security token: every garage write the agent
        // makes this turn lands under this owner via OwnerContext, never a client-supplied field.
        public string $owner,
        public string $selectedId = '',
        public string $summary = '',
        // The call-to-action card the dashboard is currently showing (headline + finding + option
        // labels, compacted client-side). Carried so a terse reply to it ("la 2.0 16V", "oui") is
        // interpreted against the question rather than re-routed blind — turns stay stateless, this
        // is the one bounded breadcrumb, not a conversation history.
        public string $pendingContext = '',
        // The interface language, resolved from Accept-Language in the controller. Threaded for the
        // same reason `owner` is: the worker has no request to read it from, and everything the agent
        // says this turn — the heartbeat, the failure toasts, the answers themselves — follows it.
        public string $locale = Locale::DEFAULT->value,
        // The caller's Casdoor access token, forwarded to bonneref by the catalogue tools this turn calls.
        // It sits in the queue (and in the failed transport) for as long as the message does: accepted,
        // since the worker has no request to read the header from and bonneref authenticates the same token.
        #[\SensitiveParameter]
        public string $accessToken = '',
        // The files the user attached, already persisted by the controller (the worker cannot read the
        // request body). References only: the bytes are fetched from the turn-attachment store.
        /** @var list<TurnAttachmentRef> */
        public array $attachments = [],
    ) {
    }
}
