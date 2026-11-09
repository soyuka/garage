<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;

/**
 * Declares the garage agent's web-search mechanism so it can ground maintenance answers in web
 * sources on a local miss. The mechanism is provider-specific (see {@see WebSearchProvider}):
 *  - Anthropic: a native server-side tool sent as a `server_tools` block, max_uses:1 (one search
 *    per turn — searches accumulate in context across the tool-call loop, the main cost lever; the
 *    Anthropic ModelClient appends server_tools verbatim).
 *  - Qwen: DashScope's `enable_search` boolean body field; the generic ModelClient merges options
 *    verbatim into the OpenAI-compatible body. There is no per-turn search cap — the model decides
 *    whether to search; WebSearchBudget tracks Anthropic token deltas only, so it never throttles
 *    Qwen (its exhausted() stays false), which is acceptable since enable_search is a flat boolean.
 *
 * Web search is a LAST RESORT for either provider: it is withheld until the agent has run at least
 * one local tool (a ToolCallMessage carrying its result is present in the bag). On the first pass of
 * a turn the bag holds only the user instruction, so search is absent and the model must reach for
 * the garage/catalog tools first; only once those have answered insufficiently — and the loop re-runs
 * this processor against the grown bag — does search appear. This makes "search the web" an
 * impossible first move, not merely a discouraged one, complementing the prompt guidance.
 */
#[AsInputProcessor(agent: 'ai.agent.garage')]
final readonly class WebSearchInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private WebSearchBudget $budget,
        // Follows the platform the agent runs on; Off withholds web search entirely.
        private WebSearchProvider $provider = WebSearchProvider::Anthropic,
    ) {
    }

    public function processInput(Input $input): void
    {
        if (WebSearchProvider::Off === $this->provider) {
            return;
        }

        if (!$this->hasRunLocalTool($input)) {
            return;
        }

        // Turn budget spent (Anthropic only — see class docblock): pull web search from the
        // carried-over options so the model cannot keep re-searching across loop passes.
        if ($this->budget->exhausted()) {
            $this->withdrawWebSearch($input);

            return;
        }

        $options = $input->getOptions();

        match ($this->provider) {
            WebSearchProvider::Anthropic => $this->declareAnthropic($options),
            WebSearchProvider::Qwen => $this->declareQwen($options),
        };

        $input->setOptions($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function declareAnthropic(array &$options): void
    {
        // Idempotent: the agent's tool-call loop re-runs input processors against the carried-over
        // options, so re-declaring web_search would trip Anthropic's "Tool names must be unique".
        $serverTools = \is_array($options['server_tools'] ?? null) ? $options['server_tools'] : [];
        foreach ($serverTools as $serverTool) {
            if (\is_array($serverTool) && 'web_search' === ($serverTool['name'] ?? null)) {
                return;
            }
        }

        $serverTools[] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 1];
        $options['server_tools'] = $serverTools;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function declareQwen(array &$options): void
    {
        // Flat boolean — setting it on every loop pass is harmless (same value).
        $options['enable_search'] = true;
    }

    private function withdrawWebSearch(Input $input): void
    {
        $options = $input->getOptions();

        if (WebSearchProvider::Qwen === $this->provider) {
            unset($options['enable_search']);
            $input->setOptions($options);

            return;
        }

        if (!\is_array($options['server_tools'] ?? null)) {
            return;
        }

        $options['server_tools'] = array_values(array_filter(
            $options['server_tools'],
            static fn (mixed $tool): bool => !\is_array($tool) || 'web_search' !== ($tool['name'] ?? null),
        ));

        if ([] === $options['server_tools']) {
            unset($options['server_tools']);
        }

        $input->setOptions($options);
    }

    private function hasRunLocalTool(Input $input): bool
    {
        foreach ($input->getMessageBag()->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                return true;
            }
        }

        return false;
    }
}
