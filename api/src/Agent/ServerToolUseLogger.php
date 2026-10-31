<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Journal\TurnJournal;
use App\Agent\Ui\EarlyStepNarrationListener;
use App\Agent\Ui\StepNarrator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\StreamResult;

/**
 * Logs Anthropic server-side tool use (today: web_search) per model call. Server tools run inside
 * Anthropic's response — they are not Toolbox calls, leave no domain write, and the ResultConverter
 * discards the web_search blocks — so the only trace is the raw response: the `server_tool_use`
 * content blocks (carrying the query) and `usage.server_tool_use.web_search_requests`. Decorating
 * the platform is the one seam that sees every call's raw payload across the agent's tool-call loop
 * (an output processor would only see the final call and miss a mid-loop search).
 *
 * Non-stream: `getData()` reads `HttpResponse::toArray()`, which is memoised, so the converter's later
 * read costs no extra round trip — the `server_tool_use` blocks (queries) and the request count are
 * logged, and the count fed to the turn budget.
 *
 * Stream: there is no raw body to read (eagerly reading would drain the stream), so instead a
 * {@see WebSearchBudgetStreamListener} is attached to the result; it feeds the budget from the
 * `TokenUsage` deltas the stream carries. Decorating the platform is the one seam every call passes
 * through — outer turn and each nested tool-loop pass — so attaching here covers them all. Queries are
 * not logged on this path (the stream drops the server_tool_use blocks that carry the query text).
 *
 * A second listener rides the same streamed result: {@see EarlyStepNarrationListener} narrates a garage
 * tool call as soon as the model BEGINS it (`ToolCallStart`), rather than waiting for
 * {@see Toolbox\CompositeToolbox::execute()} at message end — see that listener's docblock.
 *
 * Best-effort: a failure is swallowed, never turning observability into a turn-killing error.
 */
final readonly class ServerToolUseLogger implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private LoggerInterface $logger,
        private WebSearchBudget $budget,
        private StepNarrator $narrator,
        private TurnJournal $journal,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = $this->inner->invoke($model, $input, $options);

        $stream = (bool) ($options['stream'] ?? false);
        // Record the live model on the debug journal: the active platform/model is a compile-time
        // choice in ai.yaml, so this decorator — in the path of every call — is where it is observable.
        $this->journal->recordModel('anthropic', $model instanceof Model ? $model->getName() : (string) $model, $stream);

        if ($stream) {
            $this->attachBudgetListener($result);
        } else {
            $this->logServerToolUse($result);
        }

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    private function attachBudgetListener(DeferredResult $result): void
    {
        try {
            // getResult() converts but does not iterate the stream's generator, so the stream stays
            // undrained; the listener fires later, when the worker drains the turn.
            $streamResult = $result->getResult();
        } catch (\Throwable) {
            return;
        }

        if ($streamResult instanceof StreamResult) {
            $streamResult->addListener(new WebSearchBudgetStreamListener($this->budget, $this->narrator, $this->journal));
            $streamResult->addListener(new EarlyStepNarrationListener($this->narrator));
        }
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

        // Feed the debug journal: web-search spend, token usage and the raw response payload (this is
        // the non-stream path, where the full body is readable — see the class docblock).
        $this->journal->recordWebSearch($requests, array_values(array_filter($queries)));
        $this->journal->recordLlmCall(
            ['response' => $data],
            isset($data['usage']['input_tokens']) ? (int) $data['usage']['input_tokens'] : null,
            isset($data['usage']['output_tokens']) ? (int) $data['usage']['output_tokens'] : null,
            isset($data['usage']['cache_read_input_tokens']) ? (int) $data['usage']['cache_read_input_tokens'] : null,
            isset($data['usage']['cache_creation_input_tokens']) ? (int) $data['usage']['cache_creation_input_tokens'] : null,
        );

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
