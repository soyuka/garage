<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Agent\McpToolbox;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

final class McpToolboxTest extends TestCase
{
    /**
     * A transient handshake failure that yields an empty tools/list must NOT be memoised: the garage
     * server always exposes tools, so [] is a failure. Caching it would strand the worker tool-less
     * for its whole lifetime (the agent silently loses every garage tool until restart).
     */
    public function testEmptyToolListIsNotMemoisedSoItCanSelfHeal(): void
    {
        $toolbox = $this->toolboxDriving([
            ['result' => ['tools' => []]],                                  // 1st tools/list: blip
            ['result' => ['tools' => [['name' => 'list_vehicles']]]],       // 2nd: recovered
        ]);

        $this->assertSame([], $toolbox->getTools(), 'first (failed) fetch yields no tools');
        $this->assertCount(1, $toolbox->getTools(), 'next fetch re-runs and recovers the catalog');
        $this->assertSame(2, $toolbox->driveCount, 'the empty result must not have been cached');
    }

    public function testNonEmptyToolListIsMemoised(): void
    {
        $toolbox = $this->toolboxDriving([
            ['result' => ['tools' => [['name' => 'list_vehicles']]]],
            ['result' => ['tools' => [['name' => 'list_vehicles'], ['name' => 'plan_maintenance']]]],
        ]);

        $this->assertCount(1, $toolbox->getTools());
        $this->assertCount(1, $toolbox->getTools(), 'a successful catalog is cached, not re-fetched');
        $this->assertSame(1, $toolbox->driveCount);
    }

    public function testExcludedBrowseRootsAreFilteredOut(): void
    {
        $toolbox = $this->toolboxDriving([
            ['result' => ['tools' => [['name' => 'list_vehicles'], ['name' => 'list_makes']]]],
        ]);

        $names = array_map(static fn ($t) => $t->getName(), $toolbox->getTools());

        $this->assertSame(['list_vehicles'], $names);
    }

    /**
     * @param list<array<string, mixed>> $responses successive decoded JSON-RPC responses to return
     */
    private function toolboxDriving(array $responses): McpToolbox
    {
        // Override the transport seam only — the real getTools() filter + memoise policy runs.
        return new class($this->server(), $responses) extends McpToolbox {
            public int $driveCount = 0;

            /** @param list<array<string, mixed>> $responses */
            public function __construct(Server $server, private array $responses)
            {
                parent::__construct($server);
            }

            protected function drive(array $request): array
            {
                return $this->responses[$this->driveCount++] ?? [];
            }
        };
    }

    private function server(): Server
    {
        // Never touched (drive() is overridden), so the unconfigured instance is fine.
        return (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    }
}
