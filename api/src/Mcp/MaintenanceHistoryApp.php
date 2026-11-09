<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The maintenance-history MCP App (io.modelcontextprotocol/ui). Tool-less on purpose: the tool that
 * feeds it is the API Platform `get_maintenance_history`, which links this URI in its own `_meta.ui`.
 */
#[AsMcpApp(uri: self::URI, title: 'Maintenance history')]
final readonly class MaintenanceHistoryApp
{
    public const URI = 'ui://mon-parc-auto/maintenance-history';

    /**
     * Inlined in cascade order. Fonts are left out so the fragment stays fetch-free: it renders in the
     * host's iframe, where a same-origin <link> would resolve against the host.
     */
    private const TOKEN_FILES = ['colors.css', 'typography.css', 'spacing.css', 'effects.css'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/mcp-ui')]
        private string $appDir,
        #[Autowire('%kernel.project_dir%/public/design/tokens')]
        private string $tokenDir,
    ) {
    }

    public function __invoke(): TextResourceContents
    {
        $tokens = '';
        foreach (self::TOKEN_FILES as $token) {
            $tokens .= self::read($this->tokenDir.'/'.$token)."\n";
        }

        return new TextResourceContents(
            self::URI,
            McpApps::MIME_TYPE,
            str_replace('</head>', "<style>\n".$tokens."</style>\n</head>", self::read($this->appDir.'/maintenance-history.html')),
            // The app loads nothing external, so the host's default same-origin CSP stands.
            ['ui' => (new UiResourceContentMeta(prefersBorder: true))->jsonSerialize()],
        );
    }

    private static function read(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('MCP UI resource file "%s" is missing or unreadable.', $path));
        }

        return $contents;
    }
}
