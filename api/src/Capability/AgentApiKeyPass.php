<?php

declare(strict_types=1);

namespace App\Capability;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Publishes, as `app.agent.api_key`, the key of the platform the agent actually runs on, so
 * {@see Capability::Agent} follows `ai.yaml` when it is switched to another provider. The value is
 * the raw `api_key` config (an `%env()%` reference, resolved at runtime), or null for a keyless one.
 *
 * Read from the `ai` extension configs because the bundle names a platform service after its config
 * path: `ai.platform.<type>` or `ai.platform.<type>.<name>`.
 */
final readonly class AgentApiKeyPass implements CompilerPassInterface
{
    private const string PREFIX = 'ai.platform.';

    public function __construct(private string $agent)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $platforms = [];
        $platformId = null;
        foreach ($container->getExtensionConfig('ai') as $config) {
            $platforms = array_replace_recursive($platforms, \is_array($config['platform'] ?? null) ? $config['platform'] : []);
            $platformId = $config['agent'][$this->agent]['platform'] ?? $platformId;
        }

        if (!\is_string($platformId)) {
            throw new LogicException(\sprintf('The ai agent "%s" is not configured, or names no platform.', $this->agent));
        }

        $platform = str_starts_with($platformId, self::PREFIX) ? self::lookup($platforms, explode('.', substr($platformId, \strlen(self::PREFIX)), 2)) : null;
        if (!\is_array($platform)) {
            throw new LogicException(\sprintf('The ai agent "%s" runs on "%s", which is not a platform configured under ai.platform.', $this->agent, $platformId));
        }

        $apiKey = $platform['api_key'] ?? null;
        $container->setParameter('app.agent.api_key', \is_string($apiKey) ? $apiKey : null);
    }

    /**
     * @param array<array-key, mixed> $platforms
     * @param list<string>            $path
     */
    private static function lookup(array $platforms, array $path): mixed
    {
        foreach ($path as $segment) {
            if (!\is_array($platforms) || !\array_key_exists($segment, $platforms)) {
                return null;
            }
            $platforms = $platforms[$segment];
        }

        return $platforms;
    }
}
