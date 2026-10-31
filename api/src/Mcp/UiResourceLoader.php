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
final readonly class UiResourceLoader implements LoaderInterface
{
    public const MAINTENANCE_HISTORY_URI = 'ui://mon-parc-auto/maintenance-history';

    /**
     * Shared design tokens inlined into every fragment, in cascade order. Fonts are excluded so
     * the fragment stays fetch-free (Inter degrades to the system-ui fallback in --mpa-font) —
     * fragments render inside the host's iframe, not our origin, so a same-origin <link> would
     * resolve against the host. Inlining keeps the single source of truth on disk yet portable.
     */
    private const TOKEN_FILES = ['colors.css', 'typography.css', 'spacing.css', 'effects.css'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/mcp-ui')]
        private string $appDir,
        #[Autowire('%kernel.project_dir%/public/design/tokens')]
        private string $tokenDir,
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
                $this->renderApp('maintenance-history.html'),
                // Resource-content _meta.ui: same-origin CSP is the host default (the app loads
                // nothing external), so only the display hint is set.
                ['ui' => (new UiResourceContentMeta(prefersBorder: true))->jsonSerialize()],
            ),
        );
    }

    /**
     * Loads a fragment and inlines the shared --mpa-* tokens into its <head>, so the fragment
     * consumes the design system without duplicating it and without an external fetch.
     */
    private function renderApp(string $file): string
    {
        $html = file_get_contents($this->appDir.'/'.$file);
        $tokens = $this->tokenStyle();

        return str_replace('</head>', $tokens.'</head>', $html);
    }

    private function tokenStyle(): string
    {
        $css = '';
        foreach (self::TOKEN_FILES as $token) {
            $css .= file_get_contents($this->tokenDir.'/'.$token)."\n";
        }

        return "<style>\n".$css."</style>\n";
    }
}
