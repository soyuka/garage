<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PartsResourceTest extends AppTestCase
{
    public function testPartCategoriesDefaultsToLeavesOnly(): void
    {
        $client = static::createClient();
        static::catalogue();
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
    }

    public function testPartCategoriesFullTree(): void
    {
        $client = static::createClient();
        static::catalogue();
        $response = $client->request('GET', '/cars/34247/part-categories?carType=CV&leafOnly=false');

        $this->assertResponseIsSuccessful();
        $this->assertSame(60, $response->toArray()['hydra:totalItems']);
    }

    public function testPartCategoriesFilteredBySearchOnPath(): void
    {
        $client = static::createClient();
        static::catalogue();
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

    public function testArticlesForCategory(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/cars/34247/part-categories/200158/articles?carType=CV');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'hydra:totalItems' => 4,
            'hydra:member' => [
                ['number' => 'EK-1522', 'brand' => 'ELOFIC', 'productGroup' => 'Air Filter'],
            ],
        ]);
    }

    public function testArticlesFallBackToTheDeclaredCarTypeDefault(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/cars/34247/part-categories/200158/articles');

        $this->assertResponseIsSuccessful();
        $this->assertSame('PC', static::bonneref()->lastRequest()['query']['carType']);
    }

    public function testABonnerefOutageIsABadGateway(): void
    {
        $client = static::createClient();
        static::bonneref()->route('/cars/500200/part-categories', '{}', 503);
        $client->request('GET', '/cars/500200/part-categories?carType=PC');

        $this->assertResponseStatusCodeSame(502);
    }

    public function testInvalidCarTypeIsRejected(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/cars/34247/part-categories?carType=NOPE');

        $this->assertResponseStatusCodeSame(422);
    }
}
