<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\ArticleGroup;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpCatalogTest extends AppTestCase
{
    use McpClientTrait;

    public function testListPartCategoriesIsEnvelopeFree(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 1446, 'carType' => 'CV', 'leafOnly' => false],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame(3, $content['totalItems']);
        $this->assertSame(3, $content['returned']);
        $this->assertFalse($content['truncated']);
        $this->assertSame(['Moteur', 'Moteur > Lubrification', 'Moteur > Lubrification > Filtre à huile'], array_column($content['items'], 'path'));

        // items are plain projections — no JSON-LD envelope keys
        $first = $content['items'][0];
        $this->assertArrayHasKey('path', $first);
        $this->assertArrayNotHasKey('@id', $first);
        $this->assertArrayNotHasKey('@type', $first);
    }

    public function testListMakesIsCappedAndPagesThroughTheCapWithOffset(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $page1 = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => [],
        ], 2));

        $this->assertGreaterThan(10, $page1['totalItems']);
        $this->assertSame(10, $page1['returned']);
        $this->assertTrue($page1['truncated']);
        $this->assertNotEmpty($page1['hint']);
        $this->assertSame(0, $page1['offset']);
        $this->assertSame(10, $page1['nextOffset']);
        $this->assertCount(10, $page1['items']);

        $page2 = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => ['offset' => 10],
        ], 3));

        $this->assertSame(10, $page2['offset']);
        $this->assertCount(10, $page2['items']);
        $this->assertSame([], array_intersect(array_column($page1['items'], 'id'), array_column($page2['items'], 'id')), 'distinct page — no overlap with page 1');

        $beyond = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => ['offset' => 999],
        ], 4));

        $this->assertSame([], $beyond['items']);
        $this->assertArrayNotHasKey('nextOffset', $beyond);
    }

    public function testListPartCategoriesWithSearchFitsUnderCap(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_part_categories',
            'arguments' => ['carId' => 1446, 'carType' => 'CV', 'search' => 'huile'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame(1, $content['totalItems']);
        $this->assertFalse($content['truncated']);
    }

    public function testListMakesIsSearchableAndEnvelopeFree(): void
    {
        $client = static::createClient();
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

        $names = array_column($all['items'], 'name');
        $needle = $names[0];

        $filtered = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_makes',
            'arguments' => ['search' => $needle],
        ], 3));

        $this->assertLessThanOrEqual($all['totalItems'], $filtered['totalItems']);
        $this->assertIsArray($filtered['items']);
        foreach ($filtered['items'] as $make) {
            $this->assertStringContainsStringIgnoringCase($needle, $make['name']);
        }
    }

    public function testListArticlesCapsBrandsPerCategory(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_articles',
            'arguments' => ['carId' => 19107, 'carType' => 'PC', 'categoryIds' => [100259]],
        ], 2);

        $content = $result['result']['structuredContent'];
        $group = array_column($content['groups'], null, 'categoryId')[100259];

        $this->assertSame(116, $group['totalArticles']);
        $this->assertGreaterThan(ArticleGroup::MAX_BRANDS, $group['totalBrands']);
        $this->assertCount(ArticleGroup::MAX_BRANDS, $group['brands']);
        $this->assertTrue($group['truncated']);
    }

    public function testListArticlesGroupsByCategoryInOneCall(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_articles',
            'arguments' => [
                'carId' => 1446,
                'carType' => 'CV',
                // one populated category + one with no articles upstream
                'categoryIds' => [200136, 999999],
            ],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);

        $byCat = array_column($content['groups'], null, 'categoryId');

        $this->assertArrayHasKey(200136, $byCat);
        $this->assertSame(1, $byCat[200136]['totalArticles']);
        $this->assertSame(1, $byCat[200136]['totalBrands']);
        $byBrand = array_column($byCat[200136]['brands'], null, 'brand');
        $this->assertArrayHasKey('MANN-FILTER', $byBrand);
        $this->assertSame('W 712/73', $byBrand['MANN-FILTER']['articles'][0]['number']);

        // empty category is surfaced explicitly, not silently dropped (grouped-shape value)
        $this->assertArrayHasKey(999999, $byCat);
        $this->assertSame([], $byCat[999999]['brands']);
    }

    public function testGetServicePartsOilChangeCarriesNoFluidSection(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 1446, 'carType' => 'CV', 'service' => 'oil_change'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertSame('oil_change', $content['service']);

        $this->assertArrayNotHasKey('fluids', $content);
    }

    public function testGetServicePartsGroupsFilterCategoriesWithArticles(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_service_parts',
            'arguments' => ['carId' => 1446, 'carType' => 'CV', 'service' => 'filters'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);

        $byCat = array_column($content['categories'], null, 'categoryId');
        $this->assertArrayHasKey(200136, $byCat);
        $this->assertSame('Moteur > Lubrification > Filtre à huile', $byCat[200136]['path']);
        $this->assertSame(1, $byCat[200136]['totalArticles']);
        $this->assertCount(1, $byCat[200136]['brands']);
    }

    public function testListModelsReturnsMappedRows(): void
    {
        $client = static::createClient();
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
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC'],
        ], 2));

        $this->assertSame('ok', $content['status']);
        $this->assertSame(13, $content['totalItems']);
        $row = array_values(array_filter($content['items'], static fn (array $c): bool => 16681 === $c['id']))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('2.0 (LF17/LF18)', $row['name']);
        $this->assertSame(141, $row['powerPs']);
        $this->assertSame('2002-08', $row['yearFrom']);
        $this->assertSame('2007-08', $row['yearTo']);
    }

    public function testListCarsFiltersServerSide(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC', 'year' => 2005, 'capacityLitres' => 2.0],
        ], 2));

        $this->assertSame(5, $content['total']);
        $this->assertFalse($content['truncated']);
        $ids = array_column($content['items'], 'id');
        sort($ids);
        $this->assertSame([16681, 16683, 16844, 18827, 19107], $ids);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC', 'year' => 2005, 'capacityLitres' => 2.0, 'fuel' => 'essence'],
        ], 3));

        $ids = array_column($content['items'], 'id');
        sort($ids);
        $this->assertSame([16681, 19107], $ids);
    }

    public function testListCarsSearchAndUnfilteredTotals(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC', 'search' => 'LFF7'],
        ], 2));

        $this->assertSame(1, $content['total']);
        $this->assertSame(13, $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'list_cars',
            'arguments' => ['makeId' => 72, 'modelId' => 4887, 'carType' => 'PC'],
        ], 3))['total']);
    }
}
