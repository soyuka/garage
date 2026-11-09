<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The canonical resource URIs of this server's MCP endpoint (RFC 8707 / RFC 9728): the public origin
 * (`DEFAULT_URI`) plus any alias hostname the same deployment answers on (`MCP_RESOURCE_ALIASES`). The
 * protected-resource metadata advertises one of them and a token for /mcp must carry one in `aud`,
 * so both read this list.
 */
final readonly class McpResource
{
    /** @var non-empty-list<string> */
    private array $uris;

    /**
     * @param list<string> $aliases full resource URIs, e.g. https://garage.soyuka.me/mcp
     */
    public function __construct(string $publicOrigin, array $aliases, string $path)
    {
        $uris = [self::normalize(rtrim($publicOrigin, '/').$path)];
        foreach ($aliases as $alias) {
            if ('' !== $alias = trim($alias)) {
                $uris[] = self::normalize($alias);
            }
        }

        $this->uris = array_values(array_unique($uris));
    }

    /**
     * @return non-empty-list<string>
     */
    public function all(): array
    {
        return $this->uris;
    }

    /**
     * The URI whose origin the client reached us on (MCP clients refuse a metadata `resource` on another
     * origin), else the public one.
     */
    public function for(Request $request): string
    {
        $origin = self::origin($request->getSchemeAndHttpHost());
        foreach ($this->uris as $uri) {
            if (self::origin($uri) === $origin) {
                return $uri;
            }
        }

        return $this->uris[0];
    }

    private static function normalize(string $uri): string
    {
        $path = rtrim((string) parse_url($uri, \PHP_URL_PATH), '/');

        return self::origin($uri).$path;
    }

    private static function origin(string $uri): string
    {
        $parts = parse_url($uri);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an absolute URI.', $uri));
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;

        return $scheme.'://'.strtolower($parts['host']).(null === $port || $port === $default ? '' : ':'.$port);
    }
}
