<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Builds the MCP CallToolResult for the catalogue tools directly, bypassing ApiPlatform's
 * JSON-LD/Hydra normalization — our results are heterogeneous structured payloads
 * (ok / not_in_catalog / no_match), not a single typed resource, and the Hydra collection
 * normalizer would otherwise flatten the keyed map into a {"@type":"Collection","member":…}.
 *
 * A failure is a {@see ToolError} instead ({@see self::error()}).
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

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $status, string $reason, array $details = []): ToolError
    {
        return new ToolError($status, $reason, $details);
    }
}
