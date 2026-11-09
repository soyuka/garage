<?php

declare(strict_types=1);

namespace App\Garage;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * A tool execution error (MCP 2026-07-28 § Tools, error handling): bad input, a miss on what the call
 * addressed, a refused write, an unavailable capability or upstream. Flagged isError, its text opens with
 * the status token (`not_found: …`) so the model and a client can branch on it. No structuredContent:
 * it would have to conform to the tool's outputSchema, which describes a success.
 *
 * `status` and `reason` stay readable for the in-process callers that map them onto HTTP errors.
 */
final class ToolError extends CallToolResult
{
    /**
     * @param array<string, mixed> $details machine-readable context (candidates, topics…), appended as JSON
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        array $details = [],
    ) {
        $text = $status.': '.$reason;
        if ([] !== $details) {
            $text .= "\n".json_encode($details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        parent::__construct([new TextContent($text)], true);
    }
}
