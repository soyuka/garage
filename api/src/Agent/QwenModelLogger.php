<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Journal\TurnJournal;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * Records the live Qwen (DashScope) model on the debug journal per model call — the Qwen counterpart
 * to {@see ServerToolUseLogger}. The active platform/model is a compile-time choice in ai.yaml and is
 * not visible from the handler, so this decorator (the one place that sees the model on the wire)
 * reports it to the {@see TurnJournal}.
 *
 * It does NOT read the raw response body: the turn always streams (see {@see \App\MessageHandler\RunAgentTurnHandler}),
 * and reading the raw stream eagerly would drain it.
 *
 * Best-effort: recording on a closed journal is a no-op, so an out-of-turn call never errors.
 */
final readonly class QwenModelLogger implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private TurnJournal $journal,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = $this->inner->invoke($model, $input, $options);

        $stream = (bool) ($options['stream'] ?? false);
        $this->journal->recordModel('qwen', $model instanceof Model ? $model->getName() : (string) $model, $stream);

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
