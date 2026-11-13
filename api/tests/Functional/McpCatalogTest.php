<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\ArticleGroup;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpCatalogTest extends AppTestCase
{
    use McpClientTrait;

    public function testListPartCategoriesIsCappedAndEnvelopeFree(): void
    {
        $client = static::createClient();
        static::catalogue();
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
        static::catalogue();
        $sessionId = $this->initialize($client);

        $args = ['carId' => 34247, 'carType' => 'CV', 'leafOnly' => false];

        $page1 = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args,
        ], 2));

        $this->assertSame(0, $page1['offset']);
        $this->assertSame(10, $page1['nextOffset']);
        $this->assertCount(10, $page1['items']);

        $page2 = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args + ['offset' => 10],
        ], 3));

        $this->assertSame(10, $page2['offset']);
        $this->assertCount(10, $page2['items']);
        // distinct page — no overlap with page 1
        $this->assertNotEquals(
            array_column($page1['items'], 'id'),
            array_column($page2['items'], 'id'),
        );

        // offset past the end: empty page, no further nextOffset
        $beyond = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => $args + ['offset' => 999],
        ], 4));

        $this->assertSame([], $beyond['items']);
        $this->assertArrayNotHasKey('nextOffset', $beyond);
    }

    public function testListPartCategoriesWithSearchFitsUnderCap(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'search' => 'filter'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertLessThanOrEqual(10, $content['totalItems']);
        $this->assertFalse($content['truncated']);
    }

    public function testListMakesIsEnvelopeFreeAndForwardsTheSearch(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $all = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => [],
        ], 2));

        $this->assertSame('ok', $all['status']);
        $this->assertArrayHasKey('totalItems', $all);
        // plain projection, no JSON-LD envelope
        $this->assertArrayHasKey('name', $all['items'][0]);
        $this->assertArrayNotHasKey('@id', $all['items'][0]);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => ['search' => 'maz'],
        ], 3);

        $this->assertSame('maz', static::bonneref()->lastRequest()['query']['search']);
    }

    public function testListArticlesCapsBrandsPerCategory(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        // category 200120 has 12 upstream articles across 12 brands — the per-category cap is MAX_BRANDS
        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_articles',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'categoryIds' => [200120]],
        ], 2);

        $content = $result['result']['structuredContent'];
        $group = array_column($content['groups'], null, 'categoryId')[200120];

        $this->assertSame(12, $group['totalArticles']);
        $this->assertSame(12, $group['totalBrands']);
        $this->assertCount(ArticleGroup::MAX_BRANDS, $group['brands']);
        $this->assertTrue($group['truncated']);
    }

    public function testListArticlesGroupsByCategoryInOneCall(): void
    {
        $client = static::createClient();
        static::catalogue();
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

        // populated category keeps its articles, grouped by brand (ELOFIC carries all 3 refs)
        $this->assertArrayHasKey(200158, $byCat);
        $this->assertSame(4, $byCat[200158]['totalArticles']);
        $this->assertSame(2, $byCat[200158]['totalBrands']);
        $byBrand = array_column($byCat[200158]['brands'], null, 'brand');
        $this->assertArrayHasKey('ELOFIC', $byBrand);
        $this->assertSame('EK-1522', $byBrand['ELOFIC']['articles'][0]['number']);

        // empty category is surfaced explicitly, not silently dropped (grouped-shape value)
        $this->assertArrayHasKey(999999, $byCat);
        $this->assertSame([], $byCat[999999]['brands']);
    }

    public function testGetServicePartsOilChangeCarriesNoFluidSection(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 34247, 'carType' => 'CV', 'service' => 'oil_change'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame('oil_change', $content['service']);

        $this->assertArrayNotHasKey('fluids', $content);
    }

    public function testAMotorbikeCarTypeIsAcceptedAndForwardedToBonneref(): void
    {
        $client = static::createClient();
        static::bonneref()->fixture('/cars/101222/part-categories', 'categories_34247_leaf', ['carType' => 'MC']);
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 101222, 'carType' => 'MC'],
        ], 2));

        $this->assertSame('ok', $content['status']);
        $this->assertSame('MC', static::bonneref()->lastRequest()['query']['carType']);
    }

    public function testGetServicePartsGroupsFilterCategoriesWithArticles(): void
    {
        $client = static::createClient();
        static::catalogue();
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
        $this->assertSame(4, $byCat[200158]['totalArticles']);
        $this->assertCount(2, $byCat[200158]['brands']);
    }

    public function testListModelsReturnsMappedRows(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_models',
            'arguments' => ['makeId' => 72, 'carType' => 'PC', 'search' => '6 Hatchback'],
        ], 2));

        $this->assertSame('ok', $content['status']);
        $this->assertArrayNotHasKey('@id', $content['items'][0]);
        $row = array_values(array_filter($content['items'], static fn (array $m): bool => 4887 === $m['id']))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('6 Hatchback (GG)', $row['name']);
        $this->assertSame('2002-08', $row['yearFrom']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modelSearchProvider(): iterable
    {
        yield 'make-prefixed query' => ['MAZDA 6'];
        yield 'bare short name' => ['6'];
        yield 'lowercase prefixed' => ['mazda 6'];
    }

    #[DataProvider('modelSearchProvider')]
    public function testListModelsSearchMatchesNameWithoutMake(string $search): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_models',
            'arguments' => ['makeId' => 72, 'carType' => 'PC', 'search' => $search],
        ], 2));

        $this->assertSame('ok', $content['status']);
        $this->assertContains(4887, array_column($content['items'], 'id'));
    }

    public function testListCarsReturnsMappedRows(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC'],
        ], 2));

        $this->assertSame('ok', $content['status']);
        $this->assertSame(14, $content['totalItems']);
        $row = array_values(array_filter($content['items'], static fn (array $c): bool => 16681 === $c['id']))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('2.0 (GGES)', $row['name']);
        $this->assertSame(141, $row['powerPs']);
        $this->assertSame('2002-08', $row['yearFrom']);
        $this->assertSame('2007-08', $row['yearTo']);
    }

    public function testListCarsLeavesTheFiltersToBonneref(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC', 'year' => 2005, 'capacityLitres' => 2.0, 'fuel' => 'essence'],
        ], 2);

        $this->assertSame(
            ['carType' => 'PC', 'year' => '2005', 'capacityLitres' => '2', 'fuel' => 'essence'],
            static::bonneref()->lastRequest()['query'],
        );
    }
}
