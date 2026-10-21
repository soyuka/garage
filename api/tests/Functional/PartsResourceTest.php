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
        $this->assertSame(34, $data['hydra:totalItems']);
        $paths = array_column($data['hydra:member'], 'path');
        $this->assertContains('Filters > Air Filter', $paths);

        foreach ($data['hydra:member'] as $node) {
            $this->assertTrue($node['leaf']);
        }

        // Cache key signature is method.sha1(params incl. lang); pinned for the default lang=6
        $this->assertNotNull(self::partsApiStore()->find('getSearchTree.97ff21a115d6a2e60b1f29051da1bf103af7b480'));
    }

    public function testPartCategoriesFullTree(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV&leafOnly=false');

        $this->assertResponseIsSuccessful();
        $this->assertSame(60, $response->toArray()['hydra:totalItems']);
    }

    public function testPartCategoriesFilteredBySearchOnPath(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV&search=filter');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        // search narrows the 34 leaves down to filter-path nodes only
        $this->assertGreaterThan(0, $data['hydra:totalItems']);
        $this->assertLessThan(34, $data['hydra:totalItems']);

        $paths = array_column($data['hydra:member'], 'path');
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

        $this->assertSame($lower['hydra:totalItems'], $upper['hydra:totalItems']);
    }

    public function testArticlesForCategory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/34247/part-categories/200158/articles?carType=CV');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'hydra:totalItems' => 4,
            'hydra:member' => [
                ['number' => 'EK-1522', 'brand' => 'ELOFIC', 'productGroup' => 'Air Filter'],
            ],
        ]);
        // Cache key signature is method.sha1(params incl. lang); pinned for the default lang=6
        $this->assertNotNull(self::partsApiStore()->find('getArticles.3d7aa63567129c07408d60598fd5bcab162d4379'));
    }

    public function testArticlesFallBackToTheDeclaredCarTypeDefault(): void
    {
        $client = static::createClient();
        // No ?carType: the value must come from the declared QueryParameter's schema default (PC).
        // A wrong carType reaches partsapi as an unknown pair and comes back empty, not 404.
        $response = $client->request('GET', '/cars/19107/part-categories/100259/articles');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertGreaterThan(0, $data['hydra:totalItems']);
        $this->assertSame('Oil Filter', $data['hydra:member'][0]['productGroup']);
    }

    public function testUpstreamErrorIsNotCached(): void
    {
        $client = static::createClient();
        // carId 500200 = mock sentinel for the real 5002 "bad key" error envelope
        $client->request('GET', '/cars/500200/part-categories?carType=PC');

        $this->assertResponseStatusCodeSame(502);
        // key for getSearchTree?carId=500200&carType=PC&lang=4 — must remain a miss
        $this->assertNull(self::partsApiStore()->find('getSearchTree.c668305e64deefb873dec5a84f559c5468af8e41'));
    }

    public function testInvalidCarTypeIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/34247/part-categories?carType=NOPE');

        $this->assertResponseStatusCodeSame(422);
    }
}
