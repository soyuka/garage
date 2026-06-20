<?php

declare(strict_types=1);

namespace App\Agent;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Logs Qwen (DashScope) server-side web search per model call — the Qwen counterpart to
 * {@see ServerToolUseLogger}. When the request carries `enable_search: true` (set by
 * {@see WebSearchInputProcessor} on the Qwen profile), DashScope runs the search inside its response
 * and reports it in a top-level `search_info` block. The generic ResultConverter only keeps content
 * and token-usage deltas, so that block is discarded on conversion: the raw response body is the only
 * trace, exactly as for Anthropic's `server_tool_use` blocks.
 *
 * Non-stream only: the converted stream drops `search_info` and reading the raw stream eagerly would
 * drain it, so the Qwen turn runs non-streaming (AGENT_STREAM=0, see {@see RunAgentTurnHandler}) and
 * this decorator reads the memoised body. On a streamed call there is nothing to read, so it no-ops.
 *
 * Best-effort: any failure is swallowed — observability must never turn into a turn-killing error.
 * The exact `search_info` shape is provider-defined and only confirmable against a live deployment;
 * this reads it defensively and logs presence even when the inner shape is unfamiliar.
 */
final readonly class QwenSearchLogger implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private LoggerInterface $logger,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = $this->inner->invoke($model, $input, $options);

        if (!($options['stream'] ?? false)) {
            $this->logSearch($result);
        }

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    private function logSearch(DeferredResult $result): void
    {
        try {
            $data = $result->getRawResult()->getData();
        } catch (\Throwable) {
            return;
        }

        $searchInfo = $data['search_info'] ?? $data['choices'][0]['message']['search_info'] ?? null;
        if (!\is_array($searchInfo)) {
            return;
        }

        $results = $searchInfo['search_results'] ?? $searchInfo['results'] ?? [];
        $urls = [];
        foreach (\is_array($results) ? $results : [] as $entry) {
            if (\is_array($entry) && isset($entry['url'])) {
                $urls[] = (string) $entry['url'];
            }
        }

        // notice, not info: the worker runs `messenger:consume -v` (SHELL_VERBOSITY=1), where the
        // HttpKernel logger floors at NOTICE — an info line would never reach the logs. urls is joined,
        // not an array: that logger renders a non-scalar as the literal "[array]".
        $this->logger->notice('Qwen server web search: {count} result(s) {urls}', [
            'count' => \count($urls),
            'urls' => [] === $urls ? '(no urls in search_info)' : implode(' | ', $urls),
        ]);
    }
}
