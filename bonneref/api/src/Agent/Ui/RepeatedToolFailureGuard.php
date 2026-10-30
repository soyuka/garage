<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Agent\Exception\RepeatedToolFailureException;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Aborts a turn when the agent retries the SAME failing tool call too many times — the targeted
 * backstop for a model looping on a call it cannot fix (e.g. Qwen repeating a wrong field name even
 * after the corrective error result). Unlike the blunt `max_tool_calls`
 * count, this keys on (tool name + arguments + that the result failed), so it leaves a legitimately long
 * but PROGRESSING turn alone and only fires on genuine non-convergence.
 *
 * A failure is a catalogue tool result whose status is an error (the tools never throw; they return
 * a structured status — see {@see \App\Mcp\ToolResult}), or any result flagged isError. A different
 * argument set, or a success, resets nothing but counts under its own signature, so only true repetition
 * accumulates.
 *
 * Singleton in the consume worker, so {@see reset()} is called per turn by
 * {@see \App\MessageHandler\RunAgentTurnHandler} — otherwise counts would leak across turns.
 */
#[AsEventListener(event: ToolCallsExecuted::class)]
final class RepeatedToolFailureGuard
{
    /** Statuses a catalogue tool returns when the call did not accomplish anything and would be retried. */
    private const FAILED_STATUSES = ['invalid_input', 'not_found', 'error', 'refused'];

    /** @var array<string, int> signature => consecutive identical-failure count this turn */
    private array $failures = [];

    public function __construct(
        private readonly int $maxRepeats = 5,
    ) {
    }

    public function reset(): void
    {
        $this->failures = [];
    }

    public function __invoke(ToolCallsExecuted $event): void
    {
        foreach ($event->getToolResults() as $toolResult) {
            if (!$this->isFailure($toolResult->getResult())) {
                continue;
            }

            $signature = $this->signature($toolResult);
            $count = $this->failures[$signature] = ($this->failures[$signature] ?? 0) + 1;

            if ($count >= $this->maxRepeats) {
                throw new RepeatedToolFailureException($toolResult->getToolCall()->getName(), $count);
            }
        }
    }

    private function isFailure(mixed $result): bool
    {
        if (!$result instanceof CallToolResult) {
            return false;
        }

        return $result->isError || \in_array($result->structuredContent['status'] ?? null, self::FAILED_STATUSES, true);
    }

    private function signature(ToolResult $toolResult): string
    {
        $call = $toolResult->getToolCall();

        return $call->getName().':'.json_encode($call->getArguments());
    }
}
