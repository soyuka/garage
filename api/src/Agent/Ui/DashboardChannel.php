<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Security\OwnerContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves WHERE a render intent is published — the one seam that knows the dashboard has two
 * drivers:
 *
 *  - the in-app agent turn, which binds the caller's per-tab session topic
 *    (`…/agent/sessions/<hmac(owner, tab id)>`, {@see self::sessionTopic()}) before the loop runs, so
 *    only the tab that asked reacts;
 *  - an external MCP host (Claude Code, Goose…) calling the dashboard tools directly, which has no
 *    tab and no session id. Those intents go to the owner's PILOT topic (`…/agent/pilots/<digest>`),
 *    which every dashboard of that owner subscribes to (see {@see \App\Controller\AgentLinkController}).
 *
 * The pilot topic is a keyed digest of the owner, not the raw `sub`, so a topic never names its owner.
 * Every update is private: the subscriber token GET /agent/link mints, naming exactly the caller's
 * topics, is what keeps one owner's render intents off somebody else's EventSource. Derived (not
 * stored) so the worker, the HTTP layer and the MCP endpoint all agree without a lookup.
 */
final class DashboardChannel implements ResetInterface
{
    /** A browser-chosen session id: no separator, no path segment, bounded. */
    public const string SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    private ?string $sessionTopic = null;

    public function __construct(
        private readonly OwnerContext $owner,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $baseUri,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    /** Bind the turn's session topic (the in-app agent). Null unbinds — the pilot topic takes over. */
    public function bindSession(?string $sessionTopic): void
    {
        $this->sessionTopic = $sessionTopic;
    }

    public function reset(): void
    {
        $this->sessionTopic = null;
    }

    /**
     * The bound turn session's key ({@see self::sessionKey()}) — the last segment of the topic. It is the key the
     * cancel flag is stored under, so {@see CancelTurnListener} can tell whether THIS turn was asked
     * to stop. Null when no turn session is bound (an MCP pilot call has none to cancel).
     */
    public function sessionId(): ?string
    {
        if (null === $this->sessionTopic) {
            return null;
        }

        return substr($this->sessionTopic, (int) strrpos($this->sessionTopic, '/') + 1);
    }

    /** The owner's pilot topic: stable across processes, opaque, one per owner. */
    public function pilotTopic(string $owner): string
    {
        return rtrim($this->baseUri, '/').'/agent/pilots/'.hash_hmac('sha256', $owner, $this->secret);
    }

    /**
     * The key a turn session is published and cancelled under. The session id comes from the browser,
     * so it is bound to the owner: the same id posted by another owner lands on another topic and
     * raises another cancel flag. The pattern keeps `|` out of the id, so the HMAC input is unambiguous.
     */
    public function sessionKey(string $owner, string $sessionId): string
    {
        return hash_hmac('sha256', 'session|'.$owner.'|'.$sessionId, $this->secret);
    }

    /**
     * Nested under a digest of the owner, so one subscriber token can cover every tab of that owner
     * ({@see self::sessionTopicPattern()}): the hub cookie carrying it is shared by the whole browser.
     */
    public function sessionTopic(string $owner, string $sessionId): string
    {
        return $this->sessionsBase($owner).'/'.$this->sessionKey($owner, $sessionId);
    }

    /** A URLPattern matching every session topic of this owner and no other owner's. */
    public function sessionTopicPattern(string $owner): string
    {
        return $this->sessionsBase($owner).'/*';
    }

    private function sessionsBase(string $owner): string
    {
        return rtrim($this->baseUri, '/').'/agent/sessions/'.hash_hmac('sha256', 'sessions|'.$owner, $this->secret);
    }

    /**
     * The owner's fleet topic, where every write pushes the vehicle read-model (plate, VIN, facts,
     * health). Same reasoning as the pilot topic, with its own HMAC context so neither digest
     * reveals the other.
     */
    public function fleetTopic(string $owner): string
    {
        return rtrim($this->baseUri, '/').'/garage/owners/'.hash_hmac('sha256', 'fleet|'.$owner, $this->secret).'/vehicles';
    }

    /** Where to publish: the bound turn session, else the caller's pilot topic, else nowhere. */
    public function topic(): ?string
    {
        if (null !== $this->sessionTopic) {
            return $this->sessionTopic;
        }

        $owner = $this->owner->current();

        return null === $owner ? null : $this->pilotTopic($owner);
    }
}
