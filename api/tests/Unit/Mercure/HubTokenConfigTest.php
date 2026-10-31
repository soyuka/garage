<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MercureBundle\DependencyInjection\MercureExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
    private const string ISSUER = 'https://mon-parc-auto.fr';

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

        $provider = self::service($container, 'mercure.hub.default.jwt.provider');
        self::assertInstanceOf(TokenProviderInterface::class, $provider);
        [$header, $payload] = array_map(
            static fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR),
            \array_slice(explode('.', $provider->getJwt()), 0, 2),
        );

        self::assertSame('at+jwt', $header['typ']);
        self::assertSame(self::ISSUER, $payload['iss']);
        self::assertEqualsCanonicalizing([self::URL, self::PUBLIC_URL], (array) $payload['aud']);
        self::assertSame(['publish'], $payload['authorization_details'][0]['actions']);
        self::assertSame([['match' => '*']], $payload['authorization_details'][0]['topics']);
    }

    private static function service(ContainerBuilder $container, string $id): object
    {
        return $container->get($id);
    }
}
