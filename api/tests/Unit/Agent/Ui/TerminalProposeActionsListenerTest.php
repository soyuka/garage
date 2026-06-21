<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\TerminalProposeActionsListener;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;

final class TerminalProposeActionsListenerTest extends TestCase
{
    public function testEndsTheTurnWhenProposeActionsRan(): void
    {
        $event = new ToolCallsExecuted([
            new ToolResult(new ToolCall('c1', 'select_vehicle', []), 'ok'),
            new ToolResult(new ToolCall('c2', 'propose_actions', []), 'ok'),
        ]);

        (new TerminalProposeActionsListener())($event);

        $this->assertTrue($event->hasResult());
        $this->assertInstanceOf(TextResult::class, $event->getResult());
    }

    public function testLeavesTheLoopRunningWhenNoCtaRendered(): void
    {
        $event = new ToolCallsExecuted([
            new ToolResult(new ToolCall('c1', 'get_motul_oil', []), '{"status":"ambiguous"}'),
            new ToolResult(new ToolCall('c2', 'toast', []), 'ok'),
        ]);

        (new TerminalProposeActionsListener())($event);

        $this->assertFalse($event->hasResult());
    }

    public function testDoesNotOverrideAResultAnotherListenerAlreadySet(): void
    {
        $event = new ToolCallsExecuted([new ToolResult(new ToolCall('c1', 'propose_actions', []), 'ok')]);
        $event->setResult($preset = new TextResult('preset'));

        (new TerminalProposeActionsListener())($event);

        $this->assertSame($preset, $event->getResult());
    }
}
