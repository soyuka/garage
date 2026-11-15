<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Ui\DashboardChannel;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Every update is private, so GET /agent/link is the only way a dashboard gets to read its own frames:
 * it sets the hub's authorization cookie, a subscriber token naming exactly the caller's topics.
 */
final class AgentLinkSubscriberTokenTest extends AppTestCase
{
    private const string SESSION = 'sess-token-0001';
    private const string INTRUDER = 'intruder@example.com';

    public function testTheLinkGrantsASubscriptionToTheCallersTopicsOnly(): void
    {
        $client = static::createClient();
        $before = time();
        $response = $client->request('GET', '/agent/link?session='.self::SESSION);
        $after = time();
        $body = $response->toArray();
        $channel = static::getContainer()->get(DashboardChannel::class);

        $cookie = self::cookie($response->getHeaders()['set-cookie'] ?? []);
        $this->assertSame('/.well-known/mercure', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly(), 'script never reads the token');
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertArrayNotHasKey('mercureToken', $body);

        $token = self::parse((string) $cookie->getValue());
        $sessions = $channel->sessionTopicPattern(self::TEST_OWNER);
        $this->assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['subscribe'],
            'topics' => [
                ['match' => $channel->pilotTopic(self::TEST_OWNER)],
                ['match' => $channel->fleetTopic(self::TEST_OWNER)],
                ['match' => $sessions, 'match_type' => 'urlpattern'],
            ],
        ]], $token->claims()->get('authorization_details'));
        $this->assertSame($_SERVER['MERCURE_JWT_ISSUER'] ?? $_ENV['MERCURE_JWT_ISSUER'], $token->claims()->get('iss'));
        $this->assertContains($_SERVER['MERCURE_PUBLIC_URL'] ?? $_ENV['MERCURE_PUBLIC_URL'], $token->claims()->get('aud'));

        $exp = $token->claims()->get('exp')->getTimestamp();
        $this->assertGreaterThan($after, $exp);
        $this->assertSame($exp, $cookie->getExpiresTime(), 'the cookie dies with its token');
        $this->assertLessThanOrEqual($before + 86400, $exp);

        // Every tab of the owner shares the cookie, so the session grant is a pattern over the owner's
        // sessions, never another owner's.
        $prefix = self::nonEmpty(rtrim($sessions, '*'));
        $this->assertIsString($body['sessionTopic']);
        $this->assertStringStartsWith($prefix, $body['sessionTopic']);
        $this->assertStringStartsWith($prefix, $channel->sessionTopic(self::TEST_OWNER, 'another-tab'));
        $this->assertStringStartsNotWith($prefix, $channel->sessionTopic(self::INTRUDER, self::SESSION));
        $granted = json_encode($token->claims()->get('authorization_details'), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        foreach ([$channel->pilotTopic(self::INTRUDER), $channel->fleetTopic(self::INTRUDER)] as $theirs) {
            $this->assertStringNotContainsString($theirs, $granted);
        }
        $this->assertStringNotContainsString('publish', $granted);
    }

    /**
     * @param list<string> $headers
     */
    private static function cookie(array $headers): Cookie
    {
        foreach ($headers as $header) {
            $cookie = Cookie::fromString($header);
            if ('__Secure-mercure_access_token' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('GET /agent/link must set the hub authorization cookie');
    }

    private static function parse(string $jwt): UnencryptedToken
    {
        $secret = self::nonEmpty((string) ($_SERVER['MERCURE_SUBSCRIBER_JWT_SECRET'] ?? $_ENV['MERCURE_SUBSCRIBER_JWT_SECRET'] ?? ''));
        $token = new Parser(new JoseEncoder())->parse(self::nonEmpty($jwt));
        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue(
            new Validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($secret))),
            'signed with the key the hub verifies subscribers with',
        );

        return $token;
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        if ('' === $value) {
            self::fail('expected a non-empty string');
        }

        return $value;
    }
}
