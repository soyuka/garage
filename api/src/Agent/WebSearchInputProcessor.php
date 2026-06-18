<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;

/**
 * Declares Anthropic's native server-side web_search tool for the garage agent so it can
 * ground maintenance answers in cited web sources on a local miss. max_uses:1 keeps a
 * turn to a single search — searches accumulate in context across the agent's tool-call loop,
 * so this is the main cost lever. The Anthropic ModelClient appends server_tools verbatim.
 *
 * Web search is a LAST RESORT: it is withheld until the agent has run at least one local tool
 * (a ToolCallMessage carrying its result is present in the bag). On the first pass of a turn the
 * bag holds only the user instruction, so web_search is absent and the model must reach for the
 * garage/catalog tools first; only once those have answered insufficiently — and the loop re-runs
 * this processor against the grown bag — does web_search appear. This makes "search the web" an
 * impossible first move, not merely a discouraged one, complementing the prompt guidance.
 */
#[AsInputProcessor(agent: 'ai.agent.garage')]
final class WebSearchInputProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
        if (!$this->hasRunLocalTool($input)) {
            return;
        }

        $options = $input->getOptions();

        // Idempotent: the agent's tool-call loop re-runs input processors against the
        // carried-over options, so re-declaring web_search would trip Anthropic's
        // "Tool names must be unique" error.
        foreach ($options['server_tools'] ?? [] as $serverTool) {
            if ('web_search' === ($serverTool['name'] ?? null)) {
                return;
            }
        }

        $options['server_tools'][] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 1];
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
