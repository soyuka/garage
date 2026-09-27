<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

/**
 * Splits an SSE body into the payloads its frames carry.
 *
 * The SDK writes MCP answers as `event: message` frames; republishing them to the hub means taking
 * the data back out. A frame's data may span several lines, which the spec joins with a newline.
 */
final readonly class SseFrames
{
    /**
     * @return list<string>
     */
    public static function data(string $sse): array
    {
        $frames = [];

        foreach (preg_split('/\R\R/', trim($sse)) ?: [] as $block) {
            $data = [];

            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    // A single leading space after the colon is separator, not payload.
                    $data[] = preg_replace('/^ /', '', substr($line, 5)) ?? '';
                }
            }

            if ([] !== $data) {
                $frames[] = implode("\n", $data);
            }
        }

        return $frames;
    }
}
