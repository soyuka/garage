<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MercureBundle\DependencyInjection\MercureExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins config/packages/mercure.yaml against the bundle's token minting: the RFC 9068 publisher token
 * the hub (frankenphp/Caddyfile `issuer` block) accepts. The app publishes through the internal
 * MERCURE_URL while browsers subscribe through MERCURE_PUBLIC_URL, so the publisher `aud` must name
 * both or the hub's audience check depends on `resource_identifier` alone.
 */
final class HubTokenConfigTest extends TestCase
{
    private const string URL = 'http://php/.well-known/mercure';
    private const string PUBLIC_URL = 'https://localhost/.well-known/mercure';
    private const string ISSUER = 'https://bonneref.fr';

    private const array ENV = [
        'MERCURE_URL' => self::URL,
        'MERCURE_PUBLIC_URL' => self::PUBLIC_URL,
        // @mago-expect lint:no-literal-password test fixture secret
        'MERCURE_JWT_SECRET' => 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk',
        'MERCURE_JWT_ISSUER' => self::ISSUER,
        'MERCURE_SUBSCRIBER_JWT_KEY' => 'ssssssssssssssssssssssssssssssss',
    ];

    /** @var array<string, array{env: ?string, server: ?string, getenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->saved[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'getenv' => getenv($name)];
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            if (null === $before['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $before['env'];
            }
            if (null === $before['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $before['server'];
            }
            false === $before['getenv'] ? putenv($name) : putenv($name.'='.$before['getenv']);
        }
    }

    public function testPublisherTokenIsAnRfc9068AccessTokenForBothHubAddresses(): void
    {
        $provider = $this->container()->get('mercure.hub.default.jwt.provider');
        $this->assertInstanceOf(TokenProviderInterface::class, $provider);
        [$header, $payload] = array_map(
            static fn (string $part): array => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR),
            \array_slice(explode('.', $provider->getJwt()), 0, 2),
        );

        $this->assertSame('at+jwt', $header['typ']);
        $this->assertSame(self::ISSUER, $payload['iss']);
        $this->assertSame('bonneref', $payload['sub']);
        $this->assertIsArray($payload['aud']);
        $this->assertEqualsCanonicalizing([self::URL, self::PUBLIC_URL], $payload['aud']);
        $this->assertSame(['publish'], $payload['authorization_details'][0]['actions']);
        $this->assertSame([['match' => '*']], $payload['authorization_details'][0]['topics']);
    }

    public function testSubscriberTokensAreSignedWithTheHubsSubscriberKey(): void
    {
        $factory = $this->container()->get('app.test.subscriber_factory');
        $this->assertInstanceOf(TokenFactoryInterface::class, $factory);
        $jwt = $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], ['https://localhost/agent/sessions/x'])], ['exp' => new \DateTimeImmutable('+1 hour')]);
        [$header, $payload, $signature] = explode('.', $jwt);

        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, self::ENV['MERCURE_SUBSCRIBER_JWT_KEY'], true)), '+/', '-_'), '='), $signature);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(self::PUBLIC_URL, $claims['aud']);
        $this->assertSame(self::ISSUER, $claims['iss']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        foreach (self::ENV as $name => $value) {
            $container->setParameter('env('.$name.')', $value);
        }

        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/mercure.yaml');
        $this->assertIsArray($config);
        $mercure = $config['mercure'];
        $this->assertIsArray($mercure);
        (new MercureExtension())->load([$mercure], $container);
        $container->getDefinition('mercure.hub.default.jwt.provider')->setPublic(true);
        $container->setAlias('app.test.subscriber_factory', TokenFactoryInterface::class.' $defaultFactory')->setPublic(true);
        $container->compile(true);

        return $container;
    }
}
