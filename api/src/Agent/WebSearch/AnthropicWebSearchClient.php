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
final readonly class AnthropicWebSearchClient implements WebSearchClient
{
    private const string SYSTEM = <<<'PROMPT'
        You are a web research assistant for a vehicle-maintenance app. Use the web_search tool to find
        current, factual information answering the query, then reply with a concise factual answer
        grounded in what you found. Prefer manufacturer/OEM and authoritative sources. Reply in French.
        Do not ask questions and do not add caveats about being an AI — just give the grounded answer.
        PROMPT;

    public function __construct(
        private PlatformInterface $platform,
        // Carries `?max_tokens=` like the agent model string; the catalog parses it into request options.
        private string $model,
        // max_uses bounds searches WITHIN this single call (the orchestrator's per-turn count is capped
        // separately by WebSearchTool via WebSearchBudget).
        private int $maxUses = 3,
    ) {
    }

    public function search(string $query): WebSearchResult
    {
        $result = $this->platform->invoke(
            $this->model,
            new MessageBag(Message::forSystem(self::SYSTEM), Message::ofUser($query)),
            ['server_tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $this->maxUses]]],
        );

        /** @var array<string, mixed> $data */
        $data = $result->getRawResult()->getData();

        return new WebSearchResult($this->extractAnswer($data), $this->extractCitations($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractAnswer(array $data): string
    {
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
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
        $cited = $this->collect($data, $this->citationsFromText(...));

        return [] !== $cited ? $cited : $this->collect($data, $this->citationsFromResults(...));
    }

    /**
     * @param array<string, mixed>            $data
     * @param callable(array<string, mixed>): iterable<array{url: string, title: string}> $pick
     *
     * @return list<Citation>
     */
    private function collect(array $data, callable $pick): array
    {
        $seen = [];
        $citations = [];
        foreach ($data['content'] ?? [] as $block) {
            foreach ($pick($block) as $source) {
                if ('' === $source['url'] || isset($seen[$source['url']])) {
                    continue;
                }
                $seen[$source['url']] = true;
                $citations[] = new Citation($source['url'], $source['title']);
            }
        }

        return $citations;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return iterable<array{url: string, title: string}>
     */
    private function citationsFromText(array $block): iterable
    {
        if ('text' !== ($block['type'] ?? null)) {
            return;
        }

        foreach ($block['citations'] ?? [] as $citation) {
            yield ['url' => (string) ($citation['url'] ?? ''), 'title' => (string) ($citation['title'] ?? '')];
        }
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return iterable<array{url: string, title: string}>
     */
    private function citationsFromResults(array $block): iterable
    {
        if ('web_search_tool_result' !== ($block['type'] ?? null)) {
            return;
        }

        foreach ($block['content'] ?? [] as $entry) {
            if ('web_search_result' === ($entry['type'] ?? null)) {
                yield ['url' => (string) ($entry['url'] ?? ''), 'title' => (string) ($entry['title'] ?? '')];
            }
        }
    }
}
