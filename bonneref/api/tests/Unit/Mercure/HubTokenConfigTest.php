<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MercureBundle\DependencyInjection\MercureExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
        'MERCURE_JWT_SECRET' => 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk',
        'MERCURE_JWT_ISSUER' => self::ISSUER,
    ];

    /** @var array<string, array{env: ?string, server: ?string, getenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->saved[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'getenv' => getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
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
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('env(MERCURE_URL)', self::URL);
        $container->setParameter('env(MERCURE_PUBLIC_URL)', self::PUBLIC_URL);
        $container->setParameter('env(MERCURE_JWT_SECRET)', str_repeat('k', 32));
        $container->setParameter('env(MERCURE_JWT_ISSUER)', self::ISSUER);

        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/mercure.yaml');
        (new MercureExtension())->load([$config['mercure']], $container);
        $container->getDefinition('mercure.hub.default.jwt.provider')->setPublic(true);
        $container->compile(true);

        // @phpstan-ignore symfonyContainer.privateService (a ContainerBuilder of this test's own, made public just above)
        $provider = $container->get('mercure.hub.default.jwt.provider');
        [$header, $payload] = array_map(
            static fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR),
            \array_slice(explode('.', $provider->getJwt()), 0, 2),
        );

        self::assertSame('at+jwt', $header['typ']);
        self::assertSame(self::ISSUER, $payload['iss']);
        self::assertSame('bonneref', $payload['sub']);
        self::assertEqualsCanonicalizing([self::URL, self::PUBLIC_URL], (array) $payload['aud']);
        self::assertSame(['publish'], $payload['authorization_details'][0]['actions']);
        self::assertSame([['match' => '*']], $payload['authorization_details'][0]['topics']);
    }
}
