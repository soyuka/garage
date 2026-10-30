<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;

/**
 * Narrates a tool call the moment the model BEGINS it, instead of waiting for
 * {@see \App\Agent\Toolbox\CompositeToolbox::execute()} — which only runs once the whole assistant
 * message has streamed (`vendor/symfony/ai-agent/src/Toolbox/StreamListener.php` acts on
 * `ToolCallComplete`).
 *
 * The Anthropic converter yields `ToolCallStart($id, $name)` at `content_block_start`
 * (`vendor/symfony/ai-platform/src/Bridge/Anthropic/ResultConverter.php`), well before the call
 * executes. A bridge that emitted none would fall back to {@see \App\Agent\Toolbox\CompositeToolbox}'s
 * execute-time narration, which checks {@see StepNarrator::wasAnnounced()} before narrating again.
 */
final class EarlyStepNarrationListener extends AbstractStreamListener
{
    public function __construct(
        private readonly StepNarrator $narrator,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof ToolCallStart) {
            $this->narrator->announce($delta->getId(), $delta->getName());
        }
    }
}
