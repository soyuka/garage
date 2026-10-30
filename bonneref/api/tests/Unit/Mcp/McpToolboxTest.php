<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Agent\McpToolbox;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Platform\Result\ToolCall;

final class McpToolboxTest extends TestCase
{
    /**
     * A transient handshake failure that yields an empty tools/list must NOT be memoised: the server
     * always exposes tools, so [] is a failure. Caching it would strand the worker tool-less for its
     * whole lifetime.
     */
    public function testEmptyToolListIsNotMemoisedSoItCanSelfHeal(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes'], [
            ['result' => ['tools' => []]],
            ['result' => ['tools' => [['name' => 'list_makes']]]],
        ]);

        $this->assertSame([], $toolbox->getTools(), 'first (failed) fetch yields no tools');
        $this->assertCount(1, $toolbox->getTools(), 'next fetch re-runs and recovers the catalog');
        $this->assertSame(2, $toolbox->driveCount, 'the empty result must not have been cached');
    }

    public function testNonEmptyToolListIsMemoised(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes', 'list_models'], [
            ['result' => ['tools' => [['name' => 'list_makes']]]],
            ['result' => ['tools' => [['name' => 'list_makes'], ['name' => 'list_models']]]],
        ]);

        $this->assertCount(1, $toolbox->getTools());
        $this->assertCount(1, $toolbox->getTools(), 'a successful catalog is cached, not re-fetched');
        $this->assertSame(1, $toolbox->driveCount);
    }

    public function testOnlyTheAllowlistedToolsAreExposed(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes', 'decode_vin'], [
            ['result' => ['tools' => [['name' => 'list_makes'], ['name' => 'narrow_parts'], ['name' => 'decode_vin'], ['name' => 'list_articles']]]],
        ]);

        $this->assertSame(['list_makes', 'decode_vin'], array_map(static fn ($tool) => $tool->getName(), $toolbox->getTools()));
    }

    public function testAnAllowlistedToolAbsentFromTheServerIsNotInvented(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes', 'get_service_parts'], [
            ['result' => ['tools' => [['name' => 'list_makes']]]],
        ]);

        $this->assertSame(['list_makes'], array_map(static fn ($tool) => $tool->getName(), $toolbox->getTools()));
    }

    public function testAStructuredResultIsReturnedAsIs(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes'], [
            ['result' => ['structuredContent' => ['status' => 'ok'], 'content' => [['type' => 'text', 'text' => '{}']]]],
        ]);

        $this->assertSame(['status' => 'ok'], $toolbox->execute(new ToolCall('1', 'list_makes', []))->getResult());
    }

    public function testAnUnknownToolIsToolNotFound(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes'], [
            ['error' => ['code' => Error::METHOD_NOT_FOUND, 'message' => 'nope']],
        ]);

        $this->expectException(ToolNotFoundException::class);
        $toolbox->execute(new ToolCall('1', 'nope', []));
    }

    public function testAHandlerFailureIsARecoverableExecutionException(): void
    {
        $toolbox = $this->toolboxDriving(['list_makes'], [
            ['error' => ['code' => Error::INTERNAL_ERROR, 'message' => 'catalogue unreachable']],
        ]);

        $this->expectException(ToolExecutionException::class);
        $toolbox->execute(new ToolCall('1', 'list_makes', []));
    }

    /**
     * @param list<string>               $only
     * @param list<array<string, mixed>> $responses successive decoded JSON-RPC responses to return
     */
    private function toolboxDriving(array $only, array $responses): McpToolbox
    {
        // Override the transport seam only — the real getTools() filter + memoise policy runs.
        return new class($this->server(), $only, $responses) extends McpToolbox {
            public int $driveCount = 0;

            /**
             * @param list<string>               $only
             * @param list<array<string, mixed>> $responses
             */
            public function __construct(Server $server, array $only, private array $responses)
            {
                parent::__construct($server, $only);
            }

            protected function drive(array $request): array
            {
                return $this->responses[$this->driveCount++] ?? [];
            }
        };
    }

    private function server(): Server
    {
        return (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    }
}
