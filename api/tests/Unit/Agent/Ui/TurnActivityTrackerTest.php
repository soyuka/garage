<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\TurnActivityTracker;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

final class TurnActivityTrackerTest extends TestCase
{
    public function testAReadOnlyTurnIsNotProductive(): void
    {
        $tracker = new TurnActivityTracker();

        // Only read tools ran, then the model exited in prose — the silent no-op we must catch.
        $tracker(new ToolCallsExecuted([$this->call('list_vehicles')]));
        $tracker(new ToolCallsExecuted([$this->call('get_vehicle_facts')]));

        $this->assertFalse($tracker->wasProductive());
    }

    public function testAWriteMakesTheTurnProductive(): void
    {
        $tracker = new TurnActivityTracker();

        $tracker(new ToolCallsExecuted([$this->call('list_vehicles')]));
        $tracker(new ToolCallsExecuted([$this->call('log_maintenance')]));

        $this->assertTrue($tracker->wasProductive());
    }

    public function testARenderIntentMakesTheTurnProductive(): void
    {
        $tracker = new TurnActivityTracker();

        $tracker(new ToolCallsExecuted([$this->call('show_fact')]));

        $this->assertTrue($tracker->wasProductive());
    }

    public function testResetClearsProductivityBetweenTurns(): void
    {
        $tracker = new TurnActivityTracker();

        $tracker(new ToolCallsExecuted([$this->call('log_maintenance')]));
        $tracker->reset();

        $this->assertFalse($tracker->wasProductive());
    }

    private function call(string $tool): ToolResult
    {
        return new ToolResult(
            new ToolCall('id', $tool, []),
            new CallToolResult([new TextContent('{}')], false, ['status' => 'ok']),
        );
    }
}
