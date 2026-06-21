<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;

/**
 * Turns OFF qwen3.5-flash's reasoning for the garage agent. The model always emits a
 * `reasoning_content` block (~3.5k tokens observed live) that counts against max_tokens, starving the
 * actual answer + the tool calls that persist/render the result. The garage flow is tool-driven with
 * explicit prompt guidance, so the rambling chain-of-thought buys nothing here — disabling it frees the
 * whole budget and cuts latency/cost.
 *
 * DashScope honours the OpenAI-compatible `enable_thinking: false` body flag; the generic ModelClient
 * merges options verbatim into the request body (same path as `enable_search`). Gated on the model
 * name, not the provider enum, because the flag is Qwen-specific: Anthropic has no such field and would
 * reject it.
 */
#[AsInputProcessor(agent: 'ai.agent.garage')]
final readonly class QwenThinkingInputProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
        if (!str_contains(strtolower($input->getModel()), 'qwen')) {
            return;
        }

        $options = $input->getOptions();
        $options['enable_thinking'] = false;
        $input->setOptions($options);
    }
}
