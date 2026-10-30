<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use App\Tests\Mock\FixtureHttpClient;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Proves the in-process MCP bridge ({@see McpToolbox}) is a faithful, HTTP-free re-use of the
 * very same {@see \Mcp\Server} the /mcp endpoint drives, restricted to the finder's allowlist.
 */
final class AgentMcpToolboxTest extends AppTestCase
{
    use McpClientTrait;

    private const array ALLOWLIST = ['lookup_plate', 'decode_vin', 'list_makes', 'list_models', 'list_cars', 'get_service_parts', 'decode_build_code'];

    private static function toolbox(): McpToolbox
    {
        return static::getContainer()->get('test.app.finder.mcp_toolbox');
    }

    public function testGetToolsExposesExactlyTheAllowlistedServerTools(): void
    {
        $client = static::createClient();
        $names = array_map(static fn (Tool $t) => $t->getName(), self::toolbox()->getTools());

        $sessionId = $this->initialize($client);
        $served = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        $this->assertEqualsCanonicalizing(array_values(array_intersect(self::ALLOWLIST, $served)), $names);
        $this->assertEqualsCanonicalizing(self::ALLOWLIST, $names);
        foreach (['list_part_categories', 'list_articles', 'narrow_parts', 'compare_brands'] as $withheld) {
            $this->assertContains($withheld, $served);
            $this->assertNotContains($withheld, $names);
        }
    }

    public function testExecuteMatchesTheHttpMcpPayloadVerbatim(): void
    {
        $client = static::createClient();

        $inProcess = self::toolbox()->execute(new ToolCall('1', 'list_makes', []))->getResult();

        $sessionId = $this->initialize($client);
        $http = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => new \stdClass(),
        ], 2)['result']['structuredContent'];

        $this->assertSame($http, $inProcess);
        $this->assertNotEmpty($inProcess);
    }

    public function testDecodeBuildCodeMatchesTheHttpMcpPayloadVerbatim(): void
    {
        $client = static::createClient();
        $arguments = ['make' => 'PEUGEOT', 'date' => '2004-09-15'];

        $inProcess = self::toolbox()->execute(new ToolCall('1', 'decode_build_code', $arguments))->getResult();

        $sessionId = $this->initialize($client);
        $http = $this->call($client, $sessionId, 'tools/call', ['name' => 'decode_build_code', 'arguments' => $arguments], 2)['result']['structuredContent'];

        $this->assertSame($http, $inProcess);
        $this->assertSame('10173', $inProcess['organisationNumber']);
    }

    public function testUpstreamFailureBecomesARecoverableToolResult(): void
    {
        static::createClient();

        // A stalled SIV upstream once aborted a whole agent turn with an uncaught exception; it comes
        // back as a structured result so the model can tell a dead upstream from an unknown plate.
        $result = self::toolbox()->execute(new ToolCall('x', 'lookup_plate', [
            'plate' => FixtureHttpClient::TIMEOUT_PLATE,
        ]))->getResult();

        $this->assertSame('upstream_unavailable', $result['status']);
        $this->assertTrue($result['retryable']);
    }
}
