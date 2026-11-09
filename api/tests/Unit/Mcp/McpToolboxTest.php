<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Agent\Exception\RepeatedToolFailureException;
use App\Agent\Ui\RepeatedToolFailureGuard;
use App\Tests\Support\ScriptedMcpToolbox;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Result\ToolCall;

final class McpToolboxTest extends TestCase
{
    /**
     * A transient handshake failure that yields an empty tools/list must NOT be memoised: the garage
     * server always exposes tools, so [] is a failure. Caching it would strand the worker tool-less
     * for its whole lifetime (the agent silently loses every garage tool until restart).
     */
    public function testEmptyToolListIsNotMemoisedSoItCanSelfHeal(): void
    {
        $toolbox = new ScriptedMcpToolbox($this->server(), [
            ['result' => ['tools' => []]],                                  // 1st tools/list: blip
            ['result' => ['tools' => [['name' => 'list_vehicles']]]],       // 2nd: recovered
        ]);

        $firstFetch = $toolbox->getTools();
        $this->assertSame([], $firstFetch, 'first (failed) fetch yields no tools');
        $this->assertCount(1, $toolbox->getTools(), 'next fetch re-runs and recovers the catalog');
        $this->assertSame(2, $toolbox->driveCount, 'the empty result must not have been cached');
    }

    public function testNonEmptyToolListIsMemoised(): void
    {
        $toolbox = new ScriptedMcpToolbox($this->server(), [
            ['result' => ['tools' => [['name' => 'list_vehicles']]]],
            ['result' => ['tools' => [['name' => 'list_vehicles'], ['name' => 'plan_maintenance']]]],
        ]);

        $this->assertCount(1, $toolbox->getTools());
        $this->assertCount(1, $toolbox->getTools(), 'a successful catalog is cached, not re-fetched');
        $this->assertSame(1, $toolbox->driveCount);
    }

    public function testExcludedBrowseRootsAreFilteredOut(): void
    {
        $toolbox = new ScriptedMcpToolbox($this->server(), [
            ['result' => ['tools' => [['name' => 'list_vehicles'], ['name' => 'list_makes']]]],
        ]);

        $names = array_map(static fn ($t) => $t->getName(), $toolbox->getTools());

        $this->assertSame(['list_vehicles'], $names);
    }

    /**
     * An isError result reaches the model as what it is — the error text, flagged — and stays a
     * CallToolResult so RepeatedToolFailureGuard can count it.
     */
    public function testAnErrorResultIsHandedOverFlagged(): void
    {
        $error = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'not_found: No vehicle at /garage/vehicles/x in the garage.']]];
        $toolbox = new ScriptedMcpToolbox($this->server(), [['result' => $error], ['result' => $error]]);
        $guard = new RepeatedToolFailureGuard(maxRepeats: 2);

        $result = $toolbox->execute(new ToolCall('1', 'get_vehicle_facts', ['vehicle' => '/garage/vehicles/x']));

        $this->assertInstanceOf(CallToolResult::class, $result->getResult());
        $this->assertTrue($result->getResult()->isError);
        $this->assertEquals([new TextContent($error['content'][0]['text'])], $result->getResult()->content);

        $guard(new ToolCallsExecuted([$result]));
        $this->expectException(RepeatedToolFailureException::class);
        $guard(new ToolCallsExecuted([$toolbox->execute(new ToolCall('2', 'get_vehicle_facts', ['vehicle' => '/garage/vehicles/x']))]));
    }

    private function server(): Server
    {
        // Never touched (drive() is overridden), so the unconfigured instance is fine.
        return (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    }
}
