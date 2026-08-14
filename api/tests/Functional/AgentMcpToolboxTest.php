<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Proves the in-process MCP bridge ({@see McpToolbox}) is a faithful, HTTP-free re-use of the
 * very same {@see \Mcp\Server} the /mcp endpoint drives: the tool catalog and the tool-call
 * payloads must be byte-for-byte what an external host sees over Streamable HTTP.
 */
final class AgentMcpToolboxTest extends AppTestCase
{
    use McpClientTrait;

    public function testGetToolsExposesTheGarageTools(): void
    {
        static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        $names = array_map(static fn (Tool $t) => $t->getName(), $toolbox->getTools());

        $this->assertContains('list_vehicles', $names);
        $this->assertContains('log_maintenance', $names);
        $this->assertContains('get_maintenance_history', $names);
        // Facts are recorded via plan_maintenance attributes now — there is no add-fact tool.
        $this->assertNotContains('add_vehicle_fact', $names);

        // The usage-write and the checklist tools the SERVICE RECIPE drives MUST be exposed: an
        // allowlist once dropped them silently (586b223), so the prompt commanded tools the agent
        // could not see. The filter is now a denylist; assert the recipe's surface stays reachable.
        $this->assertContains('update_vehicle', $names);
        $this->assertContains('plan_maintenance', $names);
        $this->assertContains('get_service_parts', $names);
        // The generic-baseline catalogue exists to stop the agent web-searching an interval and
        // writing the guess onto a vehicle — withholding it from the agent would defeat the point.
        $this->assertContains('list_service_defaults', $names);

        // Withheld: the make/model browse roots and the granular catalog walk (get_service_parts
        // bundles the latter in one call — fewer hops, smaller agent context).
        $this->assertNotContains('list_makes', $names);
        $this->assertNotContains('list_models', $names);
        $this->assertNotContains('get_fill_volumes', $names);
        $this->assertNotContains('list_part_categories', $names);
        $this->assertNotContains('list_articles', $names);
    }

    public function testExecuteMatchesTheHttpMcpPayloadVerbatim(): void
    {
        $client = static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        // Seed one vehicle through the in-process bridge (a write tool). Facts ride on a
        // plan_maintenance item's attributes now — there is no add-fact tool.
        $toolbox->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'type' => 'oil_change',
            'attributes' => [['key' => 'oil_type', 'value' => 'SAE 5W-30', 'source' => 'llm']],
        ]));

        // list_vehicles over the in-process bridge...
        $inProcess = $toolbox->execute(new ToolCall('1', 'list_vehicles', []))->getResult();

        // ...must equal list_vehicles over real Streamable HTTP.
        $sessionId = $this->initialize($client);
        $http = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_vehicles',
            'arguments' => [],
        ], 2)['result']['structuredContent'];

        $this->assertSame($http, $inProcess);
        $this->assertSame(1, $inProcess['count']);
        $this->assertSame('TORO', $inProcess['vehicles'][0]['make']);
    }

    public function testCatalogUpstreamFailureBecomesARecoverableToolError(): void
    {
        static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        // carId 500200 makes the parts-API fixture answer HTTP 401 (auth/rate-limit) — the upstream
        // failure that once aborted a whole agent turn with an uncaught exception. execute() must
        // raise a ToolExecutionException, the catchable type FaultTolerantToolbox turns into an error
        // tool-result for the model, instead of letting the raw UpstreamException escape the loop.
        $this->expectException(ToolExecutionException::class);

        $toolbox->execute(new ToolCall('x', 'get_service_parts', [
            'carId' => 500200,
            'service' => 'oil_change',
        ]));
    }
}
