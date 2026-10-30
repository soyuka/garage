<?php

declare(strict_types=1);

namespace App\Message;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderIntent;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Finder\PartPhotoHints;
use App\Agent\TurnSurface;

/**
 * One agent turn, queued for the async worker. Carries the user instruction plus the page
 * snapshot the HTTP request held, and the *absolute* per-session Mercure topic the render intents
 * must publish to — resolved in the controller (it needs the request scheme/host) so the worker,
 * which has no request, can bind it verbatim.
 */
final readonly class RunAgentTurn
{
    public function __construct(
        public string $instruction,
        public string $sessionTopic,
        // The authenticated owner the turn runs as. Threaded from the controller because the worker
        // has no request and no security token: it lands under this owner via OwnerContext, never a
        // client-supplied field.
        public string $owner,
        public string $summary = '',
        // The question card the page is currently showing (headline + finding + option
        // labels, compacted client-side). Carried so a terse reply to it ("la 2.0 16V", "oui") is
        // interpreted against the question rather than re-routed blind — turns stay stateless, this
        // is the one bounded breadcrumb, not a conversation history.
        public string $pendingContext = '',
        // The interface language, resolved from Accept-Language in the controller. Threaded for the
        // same reason `owner` is: the worker has no request to read it from, and everything the agent
        // says this turn — the heartbeat, the failure toasts, the answers themselves — follows it.
        public string $locale = 'fr',
        public TurnSurface $surface = TurnSurface::Finder,
        // The car the page already resolved, if any.
        public ?FinderVehicle $vehicle = null,
        public FinderIntent $intent = FinderIntent::Text,
        public ?PartPhotoHints $photoHints = null,
        // Photos ride a short-lived stash, never this message (see TurnImageStash): only the
        // key and each image's shape travel through the queue.
        public ?string $imageStash = null,
        /** @var list<array{mimeType: string, bytes: int}> */
        public array $images = [],
        public ?BuildLabelHints $buildLabel = null,
    ) {
    }
}
