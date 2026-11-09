<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpMotulOilTest extends AppTestCase
{
    use McpClientTrait;

    public function testGetMotulOilReturnsEngineGradeForPinnedVariant(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_motul_oil',
            'arguments' => ['search' => 'Mazda 6', 'year' => 2004, 'engine' => '1.8'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('matched', $content['status']);
        $this->assertSame('9950', $content['vehicle']['id']);
        $this->assertSame(['5W-30'], $content['engineOilGrades']);
        $this->assertSame('20000 km/ 12 months', $content['oilChangeInterval']);
        $this->assertContains('4.6 L', $content['engineOilCapacities']);
        $this->assertNotEmpty($content['aceaStandards']);
        $this->assertStringContainsString('recommendations/9950', $content['sourceUrl']);
    }

    public function testGetMotulOilIsAmbiguousWhenVariantNotPinned(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_motul_oil',
            'arguments' => ['search' => 'Mazda 6', 'year' => 2004],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ambiguous', $content['status']);
        $this->assertGreaterThan(1, \count($content['candidates']));
        $this->assertArrayNotHasKey('engineOilGrades', $content);
    }

    public function testGetMotulOilRejectsEmptySearch(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_motul_oil',
            'arguments' => ['search' => '  '],
        ], 2);

        $this->assertSame('invalid_input', $this->outcome($result)['error']);
    }
}
