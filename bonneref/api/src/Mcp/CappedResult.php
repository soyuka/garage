<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Builds a size-bounded MCP CallToolResult for catalog list tools. The upstream catalog
 * routinely returns hundreds of rows (≈500 part categories, ≈536 manufacturers) — far more
 * than an LLM needs and enough to blow the tool-result token cap. This caps the payload at
 * the data root and surfaces totalItems + a narrowing hint so nothing is silently dropped.
 *
 * Items are passed already projected to plain arrays (no JSON-LD @id/@type envelope).
 */
final class CappedResult
{
    public const DEFAULT_LIMIT = 10;

    /**
     * The page size (limit) is deliberately fixed server-side and NOT caller-overridable —
     * a caller-supplied limit would let the LLM pass a huge value and defeat the cap. Callers
     * page with $offset instead; each page still returns at most $limit items.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function of(array $items, string $noun, ?string $narrowHint = null, int $offset = 0, int $limit = self::DEFAULT_LIMIT, bool $pageable = true): CallToolResult
    {
        $total = \count($items);
        $offset = max(0, $offset);
        $shown = \array_slice($items, $offset, $limit);
        $returned = \count($shown);
        $hasMore = $offset + $returned < $total;

        $payload = [
            'status' => 'ok',
            'totalItems' => $total,
            'total' => $total,
            'offset' => $offset,
            'returned' => $returned,
            'truncated' => $total > $limit,
            'items' => $shown,
        ];

        if ($hasMore && !$pageable) {
            $payload['hint'] = sprintf(
                '%d %s matched; only the first %d are shown, so this list is INCOMPLETE. Do not page: narrow the result.%s',
                $total,
                $noun,
                $returned,
                null !== $narrowHint ? ' '.$narrowHint : '',
            );
        } elseif ($hasMore) {
            $payload['nextOffset'] = $offset + $returned;
            $payload['hint'] = sprintf(
                '%d %s matched; showing %d from offset %d. Page on with offset=%d, or narrow the result.%s',
                $total,
                $noun,
                $returned,
                $offset,
                $offset + $returned,
                null !== $narrowHint ? ' '.$narrowHint : '',
            );
        }

        return new CallToolResult([new TextContent(json_encode($payload, \JSON_THROW_ON_ERROR))], false, $payload);
    }
}
