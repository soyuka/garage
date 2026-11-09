<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Carries the current tenant — the authenticated Casdoor `sub` — across the invocation boundaries
 * that do NOT thread identity explicitly: the in-process MCP {@see \App\Mcp\Agent\McpToolbox}
 * → API Platform state pipeline, and the async agent worker ({@see \App\MessageHandler\RunAgentTurnHandler}).
 *
 * Precedence is deliberate. On an HTTP request the security token wins: the `api`/`mcp` firewall has
 * already authenticated the bearer and provisioned the {@see \App\Entity\User}, whose identifier IS
 * the `sub`. The agent worker has no request and no token, so {@see self::set()} carries the `sub`
 * from the turn message; it is read only when no token is present. Same pattern as Symfony's own
 * TokenStorage — tenancy follows the principal, never a client-supplied field.
 */
final class OwnerContext implements ResetInterface
{
    private ?string $override = null;

    /**
     * $fallback is null in prod — an unauthenticated read genuinely has no owner. It is bound only
     * in the test env (a default principal) so the suite's anonymous reads resolve to the seeded
     * owner without every test threading a token.
     */
    public function __construct(
        private readonly Security $security,
        private readonly ?string $fallback = null,
    ) {
    }

    /** Bind the owner explicitly (the worker, from the turn message). Ignored once a token is present. */
    public function set(?string $owner): void
    {
        $this->override = $owner;
    }

    public function reset(): void
    {
        $this->override = null;
    }

    /** The current owner `sub`, or null when neither a token, an explicit binding nor a fallback is present. */
    public function current(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? $this->override ?? $this->fallback;
    }

    /** The current owner for a write; throws if none — a write must never land unowned. */
    public function require(): string
    {
        return $this->current() ?? throw new \LogicException('No authenticated owner in context for a garage write.');
    }
}
