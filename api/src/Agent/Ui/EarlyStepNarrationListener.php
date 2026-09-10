<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;

/**
 * Narrates a tool call the moment the model BEGINS it, instead of waiting for
 * {@see \App\Agent\Toolbox\CompositeToolbox::execute()} — which only runs once the whole assistant
 * message has streamed (`vendor/symfony/ai-agent/src/Toolbox/StreamListener.php` acts on
 * `ToolCallComplete`). Measured turn `01M1SM291H06DJYFFRF0M8JT01`: seven `plan_maintenance` calls sat
 * unnarrated for the 17.7 s the model spent writing them, then all fired at once.
 *
 * The Anthropic converter yields `ToolCallStart($id, $name)` at `content_block_start` and
 * `ToolInputDelta($id, $name, $partialJson)` per `input_json_delta`
 * (`vendor/symfony/ai-platform/src/Bridge/Anthropic/ResultConverter.php:216,228`) — both well before
 * the call executes. `narrate_step`'s own sentence IS its arguments, so it cannot be announced at
 * `ToolCallStart`; instead its `ToolInputDelta` pieces are buffered and decoded after each piece —
 * the Anthropic stream never emits a per-block stop delta, so a JSON that fails to parse simply means
 * "not yet complete", and a JSON that parses to `{"message": "…"}` is the completion signal.
 *
 * Qwen (the commented-out generic OpenAI-compatible bridge in `config/packages/ai.yaml`) also emits
 * both deltas — `vendor/symfony/ai-platform/src/Bridge/Generic/Completions/CompletionsConversionTrait.php:157,159`
 * — so this listener covers that path unchanged; a bridge that emitted neither would simply fall back
 * to {@see \App\Agent\Toolbox\CompositeToolbox}'s execute-time narration, which checks
 * {@see StepNarrator::wasAnnounced()} before narrating again.
 */
final class EarlyStepNarrationListener extends AbstractStreamListener
{
    private const string NARRATE_STEP = 'narrate_step';

    /**
     * Partial JSON accumulated per narrate_step call id, until it decodes to a non-empty `message`.
     *
     * @var array<string, string>
     */
    private array $narrateStepBuffers = [];

    public function __construct(
        private readonly StepNarrator $narrator,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof ToolCallStart) {
            if (self::NARRATE_STEP === $delta->getName()) {
                // The sentence is the args, not the name — start an empty buffer instead of narrating.
                $this->narrateStepBuffers[$delta->getId()] = '';

                return;
            }

            $this->narrator->announce($delta->getId(), $delta->getName());

            return;
        }

        if (!$delta instanceof ToolInputDelta || !isset($this->narrateStepBuffers[$delta->getId()])) {
            return;
        }

        $this->narrateStepBuffers[$delta->getId()] .= $delta->getPartialJson();
        // No JSON_THROW_ON_ERROR: an incomplete buffer is expected to fail to parse — that failure IS
        // the "not yet complete" signal on a stream with no per-block stop delta.
        $args = json_decode($this->narrateStepBuffers[$delta->getId()], true);

        if (!\is_array($args) || !\is_string($args['message'] ?? null) || '' === $args['message']) {
            return;
        }

        $this->narrator->announceStep($delta->getId(), $args['message']);
        unset($this->narrateStepBuffers[$delta->getId()]);
    }
}
