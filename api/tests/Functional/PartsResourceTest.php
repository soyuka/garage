<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PartsResourceTest extends AppTestCase
{
    public function testPartCategoriesDefaultsToLeavesOnly(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        // IVECO DAILY tree: 60 nodes, exactly 34 leaves (live-verified, see docs/PLAN.md)
        $this->assertSame(34, $data['totalItems']);
        $paths = array_column($data['member'], 'path');
        $this->assertContains('Filters > Air Filter', $paths);

        foreach ($data['member'] as $node) {
            $this->assertTrue($node['leaf']);
        }

        // Cache key signature must stay stable with prototype_1's paid cache
        $this->assertTrue(self::partsApiCache()->getItem('getSearchTree.e91576af1bcf40f64c680fea07f67d194b90d932')->isHit());
    }

    public function testPartCategoriesFullTree(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV&leafOnly=false');

        $this->assertResponseIsSuccessful();
        $this->assertSame(60, $response->toArray()['totalItems']);
    }

    public function testPartCategoriesFilteredBySearchOnPath(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV&search=filter');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        // search narrows the 34 leaves down to filter-path nodes only
        $this->assertGreaterThan(0, $data['totalItems']);
        $this->assertLessThan(34, $data['totalItems']);

        $paths = array_column($data['member'], 'path');
        $this->assertContains('Filters > Air Filter', $paths);
        // path is the match field (superset of name): every result carries the term
        foreach ($paths as $path) {
            $this->assertStringContainsStringIgnoringCase('filter', $path);
        }
    }

    public function testPartCategoriesSearchIsCaseInsensitive(): void
    {
        $client = static::createClient();
        $lower = $client->request('GET', '/cars/34247/part-categories?carType=CV&search=filter')->toArray();
        $upper = $client->request('GET', '/cars/34247/part-categories?carType=CV&search=FILTER')->toArray();

        $this->assertSame($lower['totalItems'], $upper['totalItems']);
    }

    public function testArticlesForCategory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/34247/part-categories/200158/articles?carType=CV');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'totalItems' => 4,
            'member' => [
                ['number' => 'EK-1522', 'brand' => 'ELOFIC', 'productGroup' => 'Air Filter'],
            ],
        ]);
        // Cache key signature must stay stable with prototype_1's paid cache
        $this->assertTrue(self::partsApiCache()->getItem('getArticles.554363ac507ba91fac1bc43ca6cedf5c36aaefd6')->isHit());
    }

    public function testFillVolumesMappedToFrenchLabelsAndSiUnits(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/fill-volumes');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $byLabel = array_column($data['member'], null, 'label');
        $this->assertArrayHasKey('Huile moteur (avec filtre)', $byLabel);
        $this->assertSame(5.5, $byLabel['Huile moteur (avec filtre)']['volume']);
        $this->assertSame('liter', $byLabel['Huile moteur (avec filtre)']['unit']);

        // Russian-unit duplicate rows (л., гр.) must be filtered out
        $units = array_unique(array_column($data['member'], 'unit'));
        $this->assertNotContains('л.', $units);
        $this->assertNotContains('гр.', $units);
    }

    public function testSuccessfulPartsCallIsCachedAndCountedOnce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/34247/fill-volumes');
        $this->assertResponseIsSuccessful();

        $pool = self::partsApiCache();
        $this->assertTrue($pool->getItem('GetFillVolumes.9082864561dcc88578d6b6c1925d0f43870e3a97')->isHit());
        $counter = $pool->getItem('calls.GetFillVolumes');
        $this->assertTrue($counter->isHit());
        $this->assertSame(1, $counter->get());

        // Second hit: served from cache, counter untouched
        $client->request('GET', '/cars/34247/fill-volumes');
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $pool->getItem('calls.GetFillVolumes')->get());
    }

    public function testBudgetExhaustedAnswers429WithoutUpstreamCall(): void
    {
        $pool = self::partsApiCache();
        $pool->save($pool->getItem('calls.getSearchTree')->set(50));

        $client = static::createClient();
        $client->request('GET', '/cars/99999/part-categories?carType=PC');

        $this->assertResponseStatusCodeSame(429);
        // "Without upstream call": nothing was paid, cached or counted
        $this->assertSame(50, $pool->getItem('calls.getSearchTree')->get());
        // key for getSearchTree?carId=99999&carType=PC&lang=4 — must remain a miss
        $this->assertFalse($pool->getItem('getSearchTree.a4978da61eb844415eb9694bb4f06d31433372d0')->isHit());
    }

    public function testCachedResponseIsStillServedWhenBudgetIsExhausted(): void
    {
        $pool = self::partsApiCache();
        $pool->save($pool->getItem('calls.GetFillVolumes')->set(50));
        $data = json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/partsapi/GetFillVolumes_34247.json'), true);
        $pool->save($pool->getItem('GetFillVolumes.9082864561dcc88578d6b6c1925d0f43870e3a97')->set($data));

        $client = static::createClient();
        $client->request('GET', '/cars/34247/fill-volumes');

        // Already-paid data keeps flowing at 50/50: cache is checked BEFORE the budget
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 17]);
    }

    public function testUpstreamErrorIsNeitherCachedNorCounted(): void
    {
        $client = static::createClient();
        // carId 500200 = mock sentinel for the real 5002 "bad key" error envelope
        $client->request('GET', '/cars/500200/part-categories?carType=PC');

        $this->assertResponseStatusCodeSame(502);
        $pool = self::partsApiCache();
        // key for getSearchTree?carId=500200&carType=PC&lang=4 — must remain a miss
        $this->assertFalse($pool->getItem('getSearchTree.c668305e64deefb873dec5a84f559c5468af8e41')->isHit());
        $this->assertFalse($pool->getItem('calls.getSearchTree')->isHit());
    }

    public function testInvalidCarTypeIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/34247/part-categories?carType=NOPE');

        $this->assertResponseStatusCodeSame(422);
    }
}
