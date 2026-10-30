<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

use Psr\Container\ContainerInterface;

/**
 * The {@see WebSearchClient} the {@see WebSearchTool} actually depends on: a thin router that picks the
 * backend implementation at runtime from {@see WebSearchBackend} (env WEB_SEARCH_BACKEND). Switching
 * provider is a one-line env edit + worker restart — no recompile, no service re-aliasing.
 *
 * The clients are behind a service locator so only the selected backend's platform is instantiated;
 * the other stays lazy and untouched (no stray API key requirement for the unused provider).
 */
final readonly class SelectableWebSearchClient implements WebSearchClient
{
    public function __construct(
        private ContainerInterface $clients,
        private WebSearchBackend $backend,
    ) {
    }

    public function search(string $query): WebSearchResult
    {
        return $this->client()->search($query);
    }

    private function client(): WebSearchClient
    {
        return $this->clients->get($this->backend->value);
    }
}
