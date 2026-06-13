<?php

declare(strict_types=1);

namespace App\Garage;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Builds the MCP CallToolResult for the garage tools directly, bypassing ApiPlatform's
 * JSON-LD/Hydra normalization — our results are heterogeneous structured payloads
 * (ok / not_in_catalog / refused), not a single typed resource, and the Hydra collection
 * normalizer would otherwise flatten the keyed map into a {"@type":"Collection","member":…}.
 *
 * Misses and refusals are returned as successful results (isError=false), never thrown,
 * so they cannot surface to the LLM as a -32603 JSON-RPC protocol error.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $structuredContent
     */
    public static function of(array $structuredContent): CallToolResult
    {
        return new CallToolResult(
            [new TextContent(json_encode($structuredContent, \JSON_THROW_ON_ERROR))],
            false,
            $structuredContent,
        );
    }
}
