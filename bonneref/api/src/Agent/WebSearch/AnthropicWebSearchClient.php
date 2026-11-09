<?php

declare(strict_types=1);

namespace App\Agent\WebSearch;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * {@see WebSearchClient} backed by a focused, one-shot Anthropic call (Haiku) with the native
 * server-side `web_search` tool. The orchestrator turn does NOT run web search itself — it calls the
 * {@see WebSearchTool}, which lands here for a separate grounded lookup. This is what lets a weak-search
 * orchestrator (e.g. Qwen) borrow Anthropic's grounding without the main loop carrying web search.
 *
 * The call is NON-STREAM: it is short (one grounded answer, well under the HttpClient idle timeout that
 * forces the main turn to stream) and the raw body is the only place citations survive — the bridge's
 * ResultConverter drops `web_search_tool_result` blocks and text-block `citations`, so we read
 * {@see PlatformInterface::invoke()}'s raw result directly instead of the converted one.
 *
 * The injected platform is the UNDECORATED Anthropic platform: routing this through
 * {@see \App\Agent\ServerToolUseLogger} would double-feed the turn's {@see \App\Agent\WebSearchBudget}
 * (the tool already records the call) and overwrite the journal's live-model with the side call.
 */
final readonly class AnthropicWebSearchClient extends AbstractWebSearchClient
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        // Carries `?max_tokens=` like the agent model string; the catalog parses it into request options.
        private string $model,
        // max_uses bounds searches WITHIN this single call (the orchestrator's per-turn count is capped
        // separately by WebSearchTool via WebSearchBudget).
        private int $maxUses = 3,
        // WEB_SEARCH_ALLOWED_DOMAINS, comma-separated; empty searches the whole web.
        private string $allowedDomains = '',
        // Journals the raw body: its usage is billed to the turn as side-call usage.
        private ?WebSearchTracer $tracer = null,
    ) {
    }

    protected function searchToolPhrase(): string
    {
        return 'the web_search tool';
    }

    public function search(string $query): WebSearchResult
    {
        $tool = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $this->maxUses];
        $domains = array_values(array_filter(array_map(trim(...), explode(',', $this->allowedDomains))));
        if ([] !== $domains) {
            $tool['allowed_domains'] = $domains;
        }
        $result = $this->platform->invoke(
            $this->model,
            new MessageBag(Message::forSystem($this->systemPrompt()), Message::ofUser($query)),
            ['server_tools' => [$tool]],
        );

        $data = $result->getRawResult()->getData();
        $this->tracer?->recordWebSearchResponse('anthropic', $query, $data, $this->model);

        if (null !== ($unavailable = $this->errorEnvelope($data))) {
            return $unavailable;
        }

        return new WebSearchResult($this->extractAnswer($data), $this->extractCitations($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractAnswer(array $data): string
    {
        $text = '';
        foreach (self::items($data['content'] ?? null) as $block) {
            if ('text' === ($block['type'] ?? null)) {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return trim($text);
    }

    /**
     * Prefer the text-block citations (the sources the model actually used). Only when none are present
     * fall back to the full `web_search_tool_result` source list. Deduped by URL, first occurrence wins.
     *
     * @param array<string, mixed> $data
     *
     * @return list<Citation>
     */
    private function extractCitations(array $data): array
    {
        $cited = $this->dedupeCitations($this->sourcesFromText($data));

        return [] !== $cited ? $cited : $this->dedupeCitations($this->sourcesFromResults($data));
    }

    /**
     * The citations the model attached to its text blocks — the sources it actually leaned on.
     *
     * @param array<string, mixed> $data
     *
     * @return iterable<array{url: string, title: string}>
     */
    private function sourcesFromText(array $data): iterable
    {
        foreach (self::items($data['content'] ?? null) as $block) {
            if ('text' !== ($block['type'] ?? null)) {
                continue;
            }
            foreach (self::items($block['citations'] ?? null) as $citation) {
                yield ['url' => (string) ($citation['url'] ?? ''), 'title' => (string) ($citation['title'] ?? '')];
            }
        }
    }

    /**
     * The full result list from every `web_search_tool_result` block — the fallback when no text-block
     * citations survived.
     *
     * @param array<string, mixed> $data
     *
     * @return iterable<array{url: string, title: string}>
     */
    private function sourcesFromResults(array $data): iterable
    {
        foreach (self::items($data['content'] ?? null) as $block) {
            if ('web_search_tool_result' !== ($block['type'] ?? null)) {
                continue;
            }
            foreach (self::items($block['content'] ?? null) as $entry) {
                if ('web_search_result' === ($entry['type'] ?? null)) {
                    yield ['url' => (string) ($entry['url'] ?? ''), 'title' => (string) ($entry['title'] ?? '')];
                }
            }
        }
    }
}
