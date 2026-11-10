<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\Vehicle;
use App\Repository\VehicleDocumentRepository;

final class DocumentationSearcher
{
    private const int SNIPPET_LENGTH = 300;

    private const int PHRASE_WINDOW = 40;

    private const int TERM_WEIGHT = 100;

    private const int PHRASE_BONUS = 10;

    private const int OCCURRENCE_CAP = 20;

    private const array STOPWORDS = [
        'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'un', 'une', 'et', 'en', 'au', 'aux', 'a',
        'pour', 'sur', 'par', 'avec', 'dans', 'ou', 'est', 'que', 'qui', 'se', 'sa', 'son', 'ses',
    ];

    /** @var array<string, string> */
    private array $folded = [];

    private ?\Transliterator $transliterator = null;

    public function __construct(
        private readonly VehicleDocumentRepository $documents,
        private readonly VehicleDocumentView $view,
    ) {
    }

    /**
     * @return list<array{documentId: string, title: string, kind: string, sourceRef: ?string, page: int, pageLabel: ?string, snippet: string, url: string}>
     */
    public function search(Vehicle $vehicle, string $query, ?VehicleDocumentKind $kind = null, int $limit = 8): array
    {
        $terms = $this->terms($query);
        if ([] === $terms) {
            return [];
        }
        $foldedQuery = trim((string) preg_replace('/\s+/u', ' ', $this->fold($query)));

        $hits = [];
        foreach ($this->documents->searchCorpus($vehicle, $kind) as $document) {
            foreach ($document->pages as $page) {
                $text = trim((string) preg_replace('/\s+/u', ' ', (string) \Normalizer::normalize($page['text'], \Normalizer::FORM_C)));
                $haystack = $this->fold($text);

                $matched = 0;
                $occurrences = 0;
                $first = null;
                foreach ($terms as $term) {
                    $position = mb_strpos($haystack, $term);
                    if (false === $position) {
                        continue;
                    }
                    ++$matched;
                    $occurrences += mb_substr_count($haystack, $term);
                    $first = null === $first ? $position : min($first, $position);
                }
                if (0 === $matched) {
                    continue;
                }

                $phrase = $this->phrasePosition($haystack, $terms, $foldedQuery);
                $score = self::TERM_WEIGHT * $matched
                    + (null !== $phrase ? self::PHRASE_BONUS : 0)
                    + min($occurrences, self::OCCURRENCE_CAP);

                $hits[] = [
                    'score' => $score,
                    'addedAt' => $document->addedAt->getTimestamp(),
                    'number' => $page['number'],
                    'hit' => [
                        'documentId' => $document->id,
                        'title' => $document->title,
                        'kind' => $document->kind->value,
                        'sourceRef' => $document->sourceRef,
                        'page' => $page['number'],
                        'pageLabel' => $page['label'],
                        'snippet' => $this->snippet($text, $phrase ?? (int) $first),
                        'url' => $this->view->urlFor($vehicle, $document->id),
                    ],
                ];
            }
        }

        usort($hits, static fn (array $a, array $b): int => [$b['score'], $b['addedAt'], $a['number']] <=> [$a['score'], $a['addedAt'], $b['number']]);

        return array_map(static fn (array $entry): array => $entry['hit'], \array_slice($hits, 0, max(1, $limit)));
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $this->fold($query)) ?: [],
            static fn (string $term): bool => '' !== $term,
        )));

        $terms = array_values(array_diff($terms, self::STOPWORDS));
        $meaningful = array_values(array_filter($terms, static fn (string $term): bool => mb_strlen($term) >= 2));

        return [] !== $meaningful ? $meaningful : $terms;
    }

    /**
     * @param list<string> $terms
     */
    private function phrasePosition(string $haystack, array $terms, string $foldedQuery): ?int
    {
        if ('' !== $foldedQuery && false !== ($verbatim = mb_strpos($haystack, $foldedQuery))) {
            return $verbatim;
        }
        if (\count($terms) < 2) {
            return null;
        }

        $offset = 0;
        while (false !== ($start = mb_strpos($haystack, $terms[0], $offset))) {
            $cursor = $start + mb_strlen($terms[0]);
            $chained = true;
            foreach (\array_slice($terms, 1) as $term) {
                $found = mb_strpos($haystack, $term, $cursor);
                if (false === $found || $found + mb_strlen($term) - $start > self::PHRASE_WINDOW) {
                    $chained = false;
                    break;
                }
                $cursor = $found + mb_strlen($term);
            }
            if ($chained) {
                return $start;
            }
            $offset = $start + 1;
        }

        return null;
    }

    private function snippet(string $text, int $position): string
    {
        $start = max(0, $position - (int) (self::SNIPPET_LENGTH / 3));
        $snippet = mb_substr($text, $start, self::SNIPPET_LENGTH);

        return ($start > 0 ? '…' : '').trim($snippet).($start + self::SNIPPET_LENGTH < mb_strlen($text) ? '…' : '');
    }

    /**
     * Lowercase and strip accents one character at a time, so folded offsets stay aligned with the
     * original text and a hit position can be used to cut the snippet from the unfolded page.
     */
    private function fold(string $text): string
    {
        $this->transliterator ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()')
            ?? throw new \LogicException('The intl transliterator is unavailable.');

        $out = '';
        foreach (mb_str_split($text) as $char) {
            if (!isset($this->folded[$char])) {
                $mapped = $this->transliterator->transliterate($char);
                $this->folded[$char] = (false === $mapped || '' === $mapped) ? $char : mb_substr($mapped, 0, 1);
            }
            $out .= $this->folded[$char];
        }

        return $out;
    }
}
