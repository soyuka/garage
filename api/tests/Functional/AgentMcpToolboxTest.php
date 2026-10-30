<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
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
        // Facts are written with set_vehicle_facts — there is no add-fact tool.
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

        // Withheld: the make/model browse roots. get_fill_volumes is gone altogether.
        $this->assertNotContains('list_makes', $names);
        $this->assertNotContains('list_models', $names);
        $this->assertNotContains('get_fill_volumes', $names);
        // The brand/reference routing in the prompt sends the agent here once the dataset has no answer.
        $this->assertContains('list_part_categories', $names);
        $this->assertContains('list_articles', $names);
    }

    public function testExecuteMatchesTheHttpMcpPayloadVerbatim(): void
    {
        $client = static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        // Seed one vehicle through the in-process bridge (a write tool).
        $toolbox->execute(new ToolCall('seed', 'plan_maintenance', [
            'make' => 'TORO',
            'model' => '246-H',
            'vehicleType' => 'groundscare',
            'type' => 'oil_change',
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
        static::bonneref()->route('/cars/500200/part-categories', '{}', 401);

        // bonneref answering HTTP 401 (a token it rejects) is the upstream failure that once aborted a
        // whole agent turn with an uncaught exception. Since 4a9dc77 it
        // comes back as a structured result rather than a ToolExecutionException: that exception's
        // getToolCallResult() discards the message, which is what made a dead upstream and an unknown
        // plate the same string to the model. The status is what lets it tell them apart.
        $result = $toolbox->execute(new ToolCall('x', 'get_service_parts', [
            'carId' => 500200,
            'service' => 'oil_change',
        ]))->getResult();

        $this->assertSame('upstream_rejected', $result['status']);
        $this->assertFalse($result['retryable'], 'a rejected upstream is not worth retrying');
        $this->assertSame('the bonneref catalog', $result['source']);
    }
}
