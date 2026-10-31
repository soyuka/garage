<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Mcp\Agent\McpToolbox;
use Mcp\Server;

/**
 * Overrides the transport seam only: the real getTools() filter and memoise policy run.
 */
final class ScriptedMcpToolbox extends McpToolbox
{
    public int $driveCount = 0;

    /**
     * @param list<string>               $only
     * @param list<array<string, mixed>> $responses successive decoded JSON-RPC responses to return
     */
    public function __construct(Server $server, array $only, private readonly array $responses)
    {
        parent::__construct($server, $only);
    }

    protected function drive(array $request): array
    {
        return $this->responses[$this->driveCount++] ?? [];
    }
}
