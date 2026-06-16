<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class McpCatalogTest extends AppTestCase
{
    use McpClientTrait;

    public function testListPartCategoriesIsCappedAndEnvelopeFree(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // full IVECO tree (60 nodes) — far over the cap, must be truncated, not dumped
        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'leafOnly' => false],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(60, $content['totalItems']);
        $this->assertSame(10, $content['returned']);
        $this->assertTrue($content['truncated']);
        $this->assertCount(10, $content['items']);
        $this->assertNotEmpty($content['hint']);

        // items are plain projections — no JSON-LD envelope keys
        $first = $content['items'][0];
        $this->assertArrayHasKey('path', $first);
        $this->assertArrayNotHasKey('@id', $first);
        $this->assertArrayNotHasKey('@type', $first);
    }

    public function testListPartCategoriesPagesThroughTheCapWithOffset(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $args = ['carId' => 34247, 'carType' => 'CV', 'leafOnly' => false];

        $page1 = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args,
        ], 2)['result']['structuredContent'];

        $this->assertSame(0, $page1['offset']);
        $this->assertSame(10, $page1['nextOffset']);
        $this->assertCount(10, $page1['items']);

        $page2 = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args + ['offset' => 10],
        ], 3)['result']['structuredContent'];

        $this->assertSame(10, $page2['offset']);
        $this->assertCount(10, $page2['items']);
        // distinct page — no overlap with page 1
        $this->assertNotEquals(
            array_column($page1['items'], 'id'),
            array_column($page2['items'], 'id'),
        );

        // offset past the end: empty page, no further nextOffset
        $beyond = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args + ['offset' => 999],
        ], 4)['result']['structuredContent'];

        $this->assertSame([], $beyond['items']);
        $this->assertArrayNotHasKey('nextOffset', $beyond);
    }

    public function testListPartCategoriesWithSearchFitsUnderCap(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'search' => 'filter'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertLessThanOrEqual(10, $content['totalItems']);
        $this->assertFalse($content['truncated']);
    }

    public function testListMakesIsSearchableAndEnvelopeFree(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $all = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => [],
        ], 2)['result']['structuredContent'];

        $this->assertSame('ok', $all['status']);
        $this->assertArrayHasKey('totalItems', $all);
        // plain projection, no JSON-LD envelope
        $this->assertArrayHasKey('name', $all['items'][0]);
        $this->assertArrayNotHasKey('@id', $all['items'][0]);

        $names = array_column($all['items'], 'name');
        $needle = $names[0];

        $filtered = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => ['search' => $needle],
        ], 3)['result']['structuredContent'];

        $this->assertLessThanOrEqual($all['totalItems'], $filtered['totalItems']);
        foreach ($filtered['items'] as $make) {
            $this->assertStringContainsStringIgnoringCase($needle, $make['name']);
        }
    }

    public function testListArticlesCapsArticlesPerCategory(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        // category 200120 has 12 upstream articles — the per-category cap is 10
        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_articles',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'categoryIds' => [200120]],
        ], 2);

        $content = $result['result']['structuredContent'];
        $group = array_column($content['groups'], null, 'categoryId')[200120];

        $this->assertSame(12, $group['totalArticles']);
        $this->assertCount(10, $group['articles']);
        $this->assertTrue($group['truncated']);
    }

    public function testListArticlesGroupsByCategoryInOneCall(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_articles',
            'arguments' => [
                'carId' => 34247,
                'carType' => 'CV',
                // one populated category + one with no articles upstream
                'categoryIds' => [200158, 999999],
            ],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);

        $byCat = array_column($content['groups'], null, 'categoryId');

        // populated category keeps its articles
        $this->assertArrayHasKey(200158, $byCat);
        $this->assertCount(4, $byCat[200158]['articles']);
        $this->assertSame('EK-1522', $byCat[200158]['articles'][0]['number']);
        $this->assertSame('ELOFIC', $byCat[200158]['articles'][0]['brand']);

        // empty category is surfaced explicitly, not silently dropped (grouped-shape value)
        $this->assertArrayHasKey(999999, $byCat);
        $this->assertSame([], $byCat[999999]['articles']);
    }

    public function testGetServicePartsBundlesEngineOilFluidForOilChange(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'service' => 'oil_change'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame('oil_change', $content['service']);

        // the engine-oil capacity + spec come back in the same call, no separate get_fill_volumes
        $byLabel = array_column($content['fluids'], null, 'label');
        $this->assertArrayHasKey('Huile moteur (avec filtre)', $byLabel);
        $this->assertSame(5.5, $byLabel['Huile moteur (avec filtre)']['volume']);
    }

    public function testGetServicePartsGroupsFilterCategoriesWithArticles(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'service' => 'filters'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);

        $byCat = array_column($content['categories'], null, 'categoryId');
        $this->assertArrayHasKey(200158, $byCat);
        $this->assertSame('Filters > Air Filter', $byCat[200158]['path']);
        $this->assertCount(4, $byCat[200158]['articles']);

        // filters carry no fluid spec
        $this->assertSame([], $content['fluids']);
    }
}
