<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpCompareBrandsTest extends AppTestCase
{
    use McpClientTrait;

    public function testRanksBrandsAgainstTheCuratedList(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'compare_brands',
            'arguments' => ['brands' => ['noname auto', 'japanparts', 'MANN-FILTER']],
        ], 3));

        $this->assertSame('ok', $outcome['status']);
        $this->assertSame('curated', $outcome['source']);
        $this->assertStringContainsString('not a verdict', $outcome['disclaimer']);
        $this->assertSame([
            ['brand' => 'MANN-FILTER', 'tier' => 1, 'known' => true],
            ['brand' => 'japanparts', 'tier' => 2, 'known' => true],
            ['brand' => 'noname auto', 'tier' => 3, 'known' => false],
        ], $outcome['ranking']);
        $this->assertSame('MANN-FILTER', $outcome['best']);
    }

    public function testNoKnownBrandHasNoBest(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'compare_brands',
            'arguments' => ['brands' => ['noname auto']],
        ], 3));

        $this->assertNull($outcome['best']);
        $this->assertFalse($outcome['ranking'][0]['known']);
    }

    public function testBlankBrandsAreInvalidInput(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $outcome = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'compare_brands',
            'arguments' => ['brands' => ['  ']],
        ], 3));

        $this->assertSame('invalid_input', $outcome['error'] ?? null);
    }

    public function testItIsAdvertisedReadOnly(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->listTools($client, $sessionId), null, 'name');

        $this->assertArrayHasKey('compare_brands', $tools);
        $this->assertTrue($tools['compare_brands']['annotations']['readOnlyHint']);
        $this->assertSame(['brands'], array_keys($tools['compare_brands']['inputSchema']['properties']));
    }
}
