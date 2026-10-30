<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\TerminalToolListener;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;

final class TerminalToolListenerTest extends TestCase
{
    public function testEndsTheTurnWhenProposeActionsRan(): void
    {
        $event = new ToolCallsExecuted([
            new ToolResult(new ToolCall('c1', 'select_vehicle', []), 'ok'),
            new ToolResult(new ToolCall('c2', 'propose_actions', []), 'ok'),
        ]);

        (new TerminalToolListener())($event);

        $this->assertTrue($event->hasResult());
        $this->assertInstanceOf(TextResult::class, $event->getResult());
    }

    public function testEndsTheTurnWhenEndTurnRan(): void
    {
        // end_turn is the explicit "I'm done" exit — the loop ends even though the model emitted a
        // tool call (the natural no-tool exit a tools-only model like Qwen never produces).
        $event = new ToolCallsExecuted([
            new ToolResult(new ToolCall('c1', 'toast', []), 'ok'),
            new ToolResult(new ToolCall('c2', 'end_turn', []), 'ok'),
        ]);

        (new TerminalToolListener())($event);

        $this->assertTrue($event->hasResult());
        $this->assertInstanceOf(TextResult::class, $event->getResult());
    }

    public function testLeavesTheLoopRunningWhenNoTerminalToolRan(): void
    {
        $event = new ToolCallsExecuted([
            new ToolResult(new ToolCall('c1', 'get_motul_oil', []), '{"status":"ambiguous"}'),
            new ToolResult(new ToolCall('c2', 'toast', []), 'ok'),
        ]);

        (new TerminalToolListener())($event);

        $this->assertFalse($event->hasResult());
    }

    public function testDoesNotOverrideAResultAnotherListenerAlreadySet(): void
    {
        $event = new ToolCallsExecuted([new ToolResult(new ToolCall('c1', 'end_turn', []), 'ok')]);
        $event->setResult($preset = new TextResult('preset'));

        (new TerminalToolListener())($event);

        $this->assertSame($preset, $event->getResult());
    }
}
