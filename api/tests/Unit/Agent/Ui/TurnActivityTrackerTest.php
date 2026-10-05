<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\TurnActivityTracker;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('productiveTools')]
    public function testEveryWriteAndRenderToolMakesTheTurnProductive(string $tool): void
    {
        $tracker = new TurnActivityTracker();

        $tracker(new ToolCallsExecuted([$this->call($tool)]));

        $this->assertTrue($tracker->wasProductive(), $tool);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function productiveTools(): iterable
    {
        foreach ([
            'log_maintenance', 'plan_maintenance', 'edit_maintenance', 'set_maintenance_schedule', 'update_vehicle', 'dismiss_maintenance',
            'select_vehicle', 'open_vehicle', 'toast', 'show_fact', 'focus_view', 'reveal_assembly', 'show_document',
            'propose_actions', 'end_turn',
        ] as $tool) {
            yield $tool => [$tool];
        }
    }

    public function testNarrationAloneIsNotProductive(): void
    {
        $tracker = new TurnActivityTracker();

        $tracker(new ToolCallsExecuted([$this->call('narrate_step'), $this->call('search_documentation'), $this->call('read_documentation_page')]));

        $this->assertFalse($tracker->wasProductive());
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
