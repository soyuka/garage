<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mcp\Agent\McpToolbox;
use Mcp\Server;

final class ScriptedMcpToolbox extends McpToolbox
{
    public int $driveCount = 0;

    /** @param list<array<string, mixed>> $responses successive decoded JSON-RPC responses to return */
    public function __construct(Server $server, private readonly array $responses)
    {
        parent::__construct($server);
    }

    protected function drive(array $request): array
    {
        return $this->responses[$this->driveCount++] ?? [];
    }
}
