<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

use App\Agent\Finder\FinderVehicle;

/**
 * Server-side rule for the finder's `web_search`, so the tool cannot be used as a free search proxy:
 * a query is allowed when it carries a reference the user gave this turn (typed, or read off the photo),
 * or after the catalogue came back empty this turn and the query names the vehicle. What the searches
 * returned is kept so `show_web_part` can only draw a reference a cited source of this turn carries.
 * Turn-scoped singleton in the worker: {@see self::reset()} per turn.
 */
final class WebSearchGate
{
    public const string NOT_ALLOWED = 'SEARCH_NOT_ALLOWED';

    private const int MIN_TOKEN_LENGTH = 2;

    private const int MIN_REFERENCE_LENGTH = 4;

    private const int MAX_QUERY_LENGTH = 200;

    private bool $catalogueMissed = false;

    /** @var list<string> normalised */
    private array $references = [];

    /** @var list<string> normalised */
    private array $vehicleWords = [];

    /** @var array<string, true> */
    private array $cited = [];

    private string $found = '';

    /**
     * @param list<string> $references
     */
    public function reset(array $references = []): void
    {
        $this->catalogueMissed = false;
        $this->vehicleWords = [];
        $this->cited = [];
        $this->found = '';
        $this->references = array_values(array_filter(
            array_map(self::compact(...), $references),
            static fn (string $r): bool => \strlen($r) >= self::MIN_REFERENCE_LENGTH,
        ));
    }

    public function bindVehicle(?FinderVehicle $vehicle): void
    {
        $words = [];
        foreach ([$vehicle?->make, $vehicle?->model] as $name) {
            $first = self::compact(explode(' ', trim((string) $name))[0]);
            if (\strlen($first) >= self::MIN_TOKEN_LENGTH) {
                $words[] = $first;
            }
        }
        $this->vehicleWords = $words;
    }

    public function recordCatalogueMiss(): void
    {
        $this->catalogueMissed = true;
    }

    /**
     * Null when the query may run, else the model-facing refusal.
     */
    public function refusal(string $query): ?string
    {
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return self::NOT_ALLOWED.\sprintf(': a query is at most %d characters: the vehicle and the part or reference, nothing else.', self::MAX_QUERY_LENGTH);
        }
        $words = array_map(self::compact(...), preg_split('/\s+/', $query) ?: []);
        $flat = self::compact($query);
        foreach ($this->references as $reference) {
            if (str_contains($flat, $reference)) {
                return null;
            }
        }
        if (!$this->catalogueMissed) {
            return self::NOT_ALLOWED.': web_search only runs for a reference the user gave this turn, or after show_parts came back empty for this vehicle this turn. Use the catalogue (show_parts) first.';
        }
        if ([] === array_intersect($this->vehicleWords, $words)) {
            return self::NOT_ALLOWED.': put the vehicle (make and model as the catalogue names them) and the part in the query.';
        }

        return null;
    }

    public function recordResult(WebSearchResult $result): void
    {
        $this->found .= ' '.$result->answer;
        foreach ($result->citations as $citation) {
            $this->cited[$citation->url] = true;
            $this->found .= ' '.$citation->title;
        }
    }

    public function cites(string $url): bool
    {
        return isset($this->cited[$url]);
    }

    public function mentions(string $reference): bool
    {
        $reference = self::compact($reference);

        return \strlen($reference) >= self::MIN_REFERENCE_LENGTH && str_contains(self::compact($this->found), $reference);
    }

    private static function compact(string $text): string
    {
        return strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }
}
