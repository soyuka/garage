<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Stream;

use App\Mcp\Stream\SseFrames;
use PHPUnit\Framework\TestCase;

final class SseFramesTest extends TestCase
{
    public function testTakesTheDataOutOfEachFrame(): void
    {
        $sse = "event: message\ndata: {\"id\":1}\n\nevent: message\ndata: {\"id\":2}\n\n";

        $this->assertSame(['{"id":1}', '{"id":2}'], SseFrames::data($sse));
    }

    /**
     * The SDK writes CRLF on some SAPIs, so the split cannot assume LF.
     */
    public function testHandlesCrlf(): void
    {
        $this->assertSame(['{"id":1}'], SseFrames::data("event: message\r\ndata: {\"id\":1}\r\n\r\n"));
    }

    public function testJoinsAMultiLineDataPayloadWithNewlines(): void
    {
        $this->assertSame(["a\nb"], SseFrames::data("event: message\ndata: a\ndata: b\n\n"));
    }

    /**
     * Only one space after the colon is the separator; the rest is payload.
     */
    public function testKeepsMeaningfulLeadingWhitespace(): void
    {
        $this->assertSame(['  padded'], SseFrames::data("data:   padded\n\n"));
    }

    public function testIgnoresFramesThatCarryNoData(): void
    {
        $this->assertSame(['{"id":1}'], SseFrames::data(": keep-alive\n\nevent: message\ndata: {\"id\":1}\n\n"));
    }

    public function testAnEmptyBodyHasNoFrames(): void
    {
        $this->assertSame([], SseFrames::data(''));
        $this->assertSame([], SseFrames::data("\n\n"));
    }
}
