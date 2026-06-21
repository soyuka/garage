<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

/**
 * One web source backing a {@see WebSearchResult}: the URL the agent persists as a fact's `url` and a
 * human title for the dashboard citation badge.
 */
final readonly class Citation
{
    public function __construct(
        public string $url,
        public string $title,
    ) {
    }
}
