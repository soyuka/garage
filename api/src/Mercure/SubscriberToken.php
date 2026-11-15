<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Agent\Ui\DashboardChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Every update is published private, so a browser only reads the topics its token names: the owner's
 * pilot and fleet topics, and every session topic of that owner. The token rides the hub's
 * authorization cookie, since EventSource cannot send a header and Mercure 1.x refuses tokens in the
 * query string. The cookie is per browser, not per tab, hence the session pattern rather than one
 * session. Signed by the hub's own factory: same key, `iss` and `aud` the hub verifies subscribers with.
 */
final readonly class SubscriberToken
{
    public function __construct(
        private TokenFactoryInterface $defaultTokenFactory,
        private HubInterface $hub,
        private DashboardChannel $channel,
        #[Autowire('%env(int:MERCURE_SUBSCRIBER_TOKEN_TTL)%')]
        private int $ttl,
    ) {
    }

    public function cookieFor(string $owner): Cookie
    {
        $expiresAt = new \DateTimeImmutable('@'.(time() + $this->ttl));
        $jwt = $this->defaultTokenFactory->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], [
                'exact' => [$this->channel->pilotTopic($owner), $this->channel->fleetTopic($owner)],
                'urlpattern' => [$this->channel->sessionTopicPattern($owner)],
            ])],
            ['exp' => $expiresAt],
        );

        return Cookie::create($this->hub->getCookieName())
            ->withValue($jwt)
            ->withExpires($expiresAt)
            ->withPath(parse_url($this->hub->getPublicUrl(), \PHP_URL_PATH) ?: '/.well-known/mercure')
            ->withSecure()
            ->withHttpOnly()
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
