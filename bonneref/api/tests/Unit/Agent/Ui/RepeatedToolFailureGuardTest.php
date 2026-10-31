<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Exception\RepeatedToolFailureException;
use App\Agent\Ui\RepeatedToolFailureGuard;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

final class RepeatedToolFailureGuardTest extends TestCase
{
    public function testThrowsAfterTheSameCallFailsTheConfiguredNumberOfTimes(): void
    {
        $guard = new RepeatedToolFailureGuard(maxRepeats: 5);

        // Four identical failures: tolerated (the model still has room to self-correct).
        for ($i = 0; $i < 4; ++$i) {
            $guard(new ToolCallsExecuted([$this->failure('log_maintenance', ['x' => 1])]));
        }

        // The fifth identical failure aborts the stuck turn.
        $this->expectException(RepeatedToolFailureException::class);
        $guard(new ToolCallsExecuted([$this->failure('log_maintenance', ['x' => 1])]));
    }

    public function testDifferentArgumentsAreCountedSeparately(): void
    {
        $guard = new RepeatedToolFailureGuard(maxRepeats: 4);

        // Each distinct argument set has its own tally: 6 alternating calls = 3 of each, under 4.
        for ($i = 0; $i < 6; ++$i) {
            $guard(new ToolCallsExecuted([$this->failure('log_maintenance', ['x' => $i % 2])]));
        }

        $this->expectNotToPerformAssertions();
    }

    public function testSuccessfulCallsDoNotCount(): void
    {
        $guard = new RepeatedToolFailureGuard(maxRepeats: 2);

        for ($i = 0; $i < 5; ++$i) {
            $guard(new ToolCallsExecuted([$this->success('toast', ['message' => 'ok'])]));
        }

        $this->expectNotToPerformAssertions();
    }

    public function testResetClearsTheTallyBetweenTurns(): void
    {
        $guard = new RepeatedToolFailureGuard(maxRepeats: 2);

        $guard(new ToolCallsExecuted([$this->failure('log_maintenance', ['x' => 1])]));
        $guard->reset();

        // After reset the previous failure no longer counts, so one more does not trip the threshold.
        $guard(new ToolCallsExecuted([$this->failure('log_maintenance', ['x' => 1])]));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string, mixed> $args
     */
    private function failure(string $tool, array $args): ToolResult
    {
        return new ToolResult(
            new ToolCall('id', $tool, $args),
            new CallToolResult([new TextContent('{}')], false, ['status' => 'invalid_input']),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function success(string $tool, array $args): ToolResult
    {
        return new ToolResult(
            new ToolCall('id', $tool, $args),
            new CallToolResult([new TextContent('{}')], false, ['status' => 'ok']),
        );
    }
}
