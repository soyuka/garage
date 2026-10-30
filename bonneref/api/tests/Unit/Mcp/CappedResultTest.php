<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\CappedResult;
use PHPUnit\Framework\TestCase;

final class CappedResultTest extends TestCase
{
    public function testNonPageableTruncationTellsToNarrowNotToPage(): void
    {
        $items = array_map(static fn (int $i): array => ['id' => $i], range(1, 45));

        $payload = CappedResult::of($items, 'vehicle variants', 'Pass year, capacityLitres, fuel or search.', limit: 30, pageable: false)->structuredContent;

        $this->assertSame(45, $payload['total']);
        $this->assertTrue($payload['truncated']);
        $this->assertSame(30, $payload['returned']);
        $this->assertArrayNotHasKey('nextOffset', $payload);
        $this->assertStringContainsString('narrow', $payload['hint']);
        $this->assertStringNotContainsString('offset', $payload['hint']);
    }

    public function testCompleteListIsNotTruncated(): void
    {
        $payload = CappedResult::of([['id' => 1]], 'vehicle variants', limit: 30, pageable: false)->structuredContent;

        $this->assertSame(1, $payload['total']);
        $this->assertFalse($payload['truncated']);
        $this->assertArrayNotHasKey('hint', $payload);
    }
}
