<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Agent\WebSearch\Citation;
use App\Agent\WebSearch\WebSearchClient;
use App\Agent\WebSearch\WebSearchResult;

final class RecordingWebSearchClient implements WebSearchClient
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly WebSearchResult $result)
    {
    }

    public static function citingContitech(): self
    {
        return new self(new WebSearchResult('Pour ce moteur, kit CONTITECH CT 1088.', [new Citation('https://example.org/ct1088', 'ContiTech CT1088')]));
    }

    public function search(string $query): WebSearchResult
    {
        $this->queries[] = $query;

        return $this->result;
    }
}
