<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Extension\Apps\McpApps;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Enables the MCP Apps extension (io.modelcontextprotocol/ui) on the server builder, so the
 * server announces `capabilities.extensions["io.modelcontextprotocol/ui"]` at initialize.
 *
 * Still wired here because our UI resources come from {@see UiResourceLoader} rather than the
 * bundle's own `apps` registry kind, which is what McpAppPass keys its enableExtension call on.
 */
final class EnableMcpAppsExtensionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mcp.server.garage.builder')) {
            return;
        }

        $container->getDefinition('mcp.server.garage.builder')
            ->addMethodCall('enableExtension', [new Definition(McpApps::class)]);
    }
}
