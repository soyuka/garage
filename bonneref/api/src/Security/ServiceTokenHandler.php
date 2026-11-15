<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The catalogue routes mpa calls accept two kinds of token. mpa's own: a client credentials token of the
 * Casdoor application `mpa-service` (`azp` and `aud` its client id, `sub` pinned), acting for the end user
 * mpa names in `X-Bonneref-Owner`, so the per-owner quotas keep counting per person. Anything else is a
 * user token and goes to {@see $users}, the REST handler with its organization and audience checks; the
 * header is never read for it. Replaced forwarding the caller's bearer on 2026-11-15 (api/docs/AUTH.md).
 */
final readonly class ServiceTokenHandler implements AccessTokenHandlerInterface
{
    public const string OWNER_HEADER = 'X-Bonneref-Owner';

    /** RFC 5321's limit on a forward path, so on any address. */
    private const int MAX_OWNER_LENGTH = 254;

    public function __construct(
        private AccessTokenVerifier $verifier,
        private AccessTokenHandlerInterface $users,
        private RequestStack $requests,
        private string $clientId,
        private string $subject,
        private ?LoggerInterface $logger = null,
    ) {
        if ('' === $clientId || '' === $subject) {
            throw new \InvalidArgumentException('MPA_SERVICE_CLIENT_ID and MPA_SERVICE_SUBJECT must be set: an empty pin would match tokens without the claim.');
        }
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $claims = $this->verifier->verify($accessToken);
        if ($this->clientId !== ($claims['azp'] ?? null)) {
            return $this->users->getUserBadgeFrom($accessToken);
        }

        $audience = $claims['aud'] ?? [];
        if (!\in_array($this->clientId, \is_array($audience) ? $audience : [$audience], true)) {
            throw $this->refused('The mpa service token was not issued for its own client.');
        }
        if ($this->subject !== ($claims['sub'] ?? null)) {
            throw $this->refused('The token of the mpa service application is not its client credentials token.');
        }

        $owner = $this->requests->getCurrentRequest()?->headers->get(self::OWNER_HEADER);
        if (null === $owner || \strlen($owner) > self::MAX_OWNER_LENGTH || false === filter_var($owner, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
            throw $this->refused(\sprintf('The mpa service must name one owner email in %s.', self::OWNER_HEADER));
        }

        return new UserBadge($owner, null, ['email' => $owner]);
    }

    private function refused(string $reason): BadCredentialsException
    {
        $this->logger?->error('Refused an mpa service token.', ['error' => $reason]);

        return new BadCredentialsException('Invalid credentials.');
    }
}
