<?php

declare(strict_types=1);

namespace App\Agent;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Logs Anthropic server-side tool use (today: web_search) per model call. Server tools run inside
 * Anthropic's response — they are not Toolbox calls, leave no domain write, and the ResultConverter
 * discards the web_search blocks — so the only trace is the raw response: the `server_tool_use`
 * content blocks (carrying the query) and `usage.server_tool_use.web_search_requests`. Decorating
 * the platform is the one seam that sees every call's raw payload across the agent's tool-call loop
 * (an output processor would only see the final call and miss a mid-loop search).
 *
 * Non-stream only: `getData()` reads `HttpResponse::toArray()`, which is memoised, so the converter's
 * later read costs no extra round trip. Streaming is skipped — eagerly reading would drain the stream.
 * Best-effort: a read failure is swallowed, never turning observability into a turn-killing error.
 */
final readonly class ServerToolUseLogger implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private LoggerInterface $logger,
        private WebSearchBudget $budget,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = $this->inner->invoke($model, $input, $options);

        if (!($options['stream'] ?? false)) {
            $this->logServerToolUse($result);
        }

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    private function logServerToolUse(DeferredResult $result): void
    {
        try {
            $data = $result->getRawResult()->getData();
        } catch (\Throwable) {
            return;
        }

        $queries = [];
        foreach ($data['content'] ?? [] as $block) {
            if ('server_tool_use' === ($block['type'] ?? null) && 'web_search' === ($block['name'] ?? null)) {
                $queries[] = (string) ($block['input']['query'] ?? '');
            }
        }

        $requests = (int) ($data['usage']['server_tool_use']['web_search_requests'] ?? 0);

        // Feed the turn budget: this decorator is the only seam that sees a search actually ran,
        // so WebSearchInputProcessor can withdraw web_search once the per-turn cap is reached.
        $this->budget->record($requests);

        if ($requests < 1 && [] === $queries) {
            return;
        }

        // notice, not info: the worker runs `messenger:consume -v` (SHELL_VERBOSITY=1), where the
        // default HttpKernel logger floors at NOTICE — an info line would never reach the logs.
        // queries is joined, not an array: that logger interpolates {placeholders} but renders a
        // non-scalar as the literal "[array]", so the searches would be invisible otherwise.
        $this->logger->notice('Anthropic server tool use: web_search x{count} {queries}', [
            'count' => $requests,
            'queries' => [] === $queries ? '(no query in this call)' : implode(' | ', $queries),
        ]);
    }
}
