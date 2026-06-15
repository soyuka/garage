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
 * Wired here, in the app, rather than in symfony/mcp-bundle: the bundle is held at a
 * 0.6-compat fork and stays generic; turning the extension on is this application's choice.
 */
final class EnableMcpAppsExtensionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mcp.server.builder')) {
            return;
        }

        $container->getDefinition('mcp.server.builder')
            ->addMethodCall('enableExtension', [new Definition(McpApps::class)]);
    }
}
