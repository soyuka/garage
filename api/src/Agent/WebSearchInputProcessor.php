<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;

/**
 * Declares Anthropic's native server-side web_search tool for the garage agent so it can
 * ground maintenance answers in cited web sources on a local miss. max_uses:1 keeps a
 * turn to a single search — searches accumulate in context across the agent's tool-call loop,
 * so this is the main cost lever. The Anthropic ModelClient appends server_tools verbatim.
 */
#[AsInputProcessor(agent: 'ai.agent.garage')]
final class WebSearchInputProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
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
}
