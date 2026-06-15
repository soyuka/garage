<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\ResourceDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registers the MCP Apps (io.modelcontextprotocol/ui) HTML resources.
 *
 * Done through a LoaderInterface rather than the #[McpResource] attribute because the
 * resources/list descriptor must carry an empty-object `_meta.ui` marker
 * ({@see McpApps::resourceMarker()}), and PHP attribute arguments are constant expressions
 * only — they cannot hold a `\stdClass`.
 */
final class UiResourceLoader implements LoaderInterface
{
    public const MAINTENANCE_HISTORY_URI = 'ui://mon-parc-auto/maintenance-history';

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/mcp-ui')]
        private readonly string $appDir,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $registry->registerResource(
            new ResourceDefinition(
                uri: self::MAINTENANCE_HISTORY_URI,
                name: 'maintenance-history-app',
                title: 'Maintenance history',
                description: 'Interactive service-history cards for a vehicle, rendered from get_maintenance_history.',
                mimeType: McpApps::MIME_TYPE,
                meta: ['ui' => McpApps::resourceMarker()],
            ),
            fn (): TextResourceContents => new TextResourceContents(
                self::MAINTENANCE_HISTORY_URI,
                McpApps::MIME_TYPE,
                file_get_contents($this->appDir.'/maintenance-history.html'),
                // Resource-content _meta.ui: same-origin CSP is the host default (the app loads
                // nothing external), so only the display hint is set.
                ['ui' => (new UiResourceContentMeta(prefersBorder: true))->jsonSerialize()],
            ),
        );
    }
}
