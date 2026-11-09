<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Accepts a verified token only when its `aud` names something this firewall serves (MCP authorization:
 * "MUST validate that access tokens were issued specifically for them", RFC 8707 §2). Casdoor sets `aud`
 * to the RFC 8707 `resource` the client asked for, else to the client_id: /mcp accepts its own resource
 * URIs, the REST firewall the client ids of our own front ends. See docs/AUTH.md.
 */
final readonly class AudienceTokenHandler implements AccessTokenHandlerInterface
{
    /** @var list<string> */
    private array $accepted;

    /**
     * @param list<string|list<string>> $audiences each entry one audience or a list of them (a csv env)
     */
    public function __construct(
        private AccessTokenHandlerInterface $verifier,
        array $audiences,
        ?McpResource $resource = null,
    ) {
        $flat = [];
        array_walk_recursive($audiences, static function (mixed $audience) use (&$flat): void {
            if (\is_string($audience) && '' !== $audience = trim($audience)) {
                $flat[] = $audience;
            }
        });

        $this->accepted = array_values(array_unique([...$flat, ...($resource?->all() ?? [])]));
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $badge = $this->verifier->getUserBadgeFrom($accessToken);

        $audience = $badge->getAttributes()['aud'] ?? [];
        $audience = array_filter(\is_array($audience) ? $audience : [$audience], \is_string(...));

        if ([] === array_intersect($audience, $this->accepted)) {
            throw new BadCredentialsException('The access token was not issued for this resource.');
        }

        return $badge;
    }
}
