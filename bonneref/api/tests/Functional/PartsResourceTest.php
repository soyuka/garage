<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PartsResourceTest extends AppTestCase
{
    public function testPartCategoriesDefaultsToLeavesOnly(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/19107/part-categories?carType=PC');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertSame(2, $data['hydra:totalItems']);
        $paths = array_column($data['hydra:member'], 'path');
        $this->assertContains('Filtre > Filtre à huile', $paths);
        $this->assertContains('Direction > Rotules', $paths);

        $this->assertIsArray($data['hydra:member']);
        foreach ($data['hydra:member'] as $node) {
            $this->assertTrue($node['leaf']);
        }
    }

    public function testPartCategoriesFullTree(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/1446/part-categories?carType=CV&leafOnly=false');

        $this->assertResponseIsSuccessful();
        $this->assertSame(3, $response->toArray()['hydra:totalItems']);
    }

    public function testPartCategoriesFilteredBySearchOnPath(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/19107/part-categories?carType=PC&search=filtre');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        // search narrows the 2 leaves down to the one whose path carries the term
        $this->assertSame(1, $data['hydra:totalItems']);

        $paths = array_column($data['hydra:member'], 'path');
        $this->assertContains('Filtre > Filtre à huile', $paths);
        foreach ($paths as $path) {
            $this->assertStringContainsStringIgnoringCase('filtre', $path);
        }
    }

    public function testPartCategoriesSearchIsCaseInsensitive(): void
    {
        $client = static::createClient();
        $lower = $client->request('GET', '/cars/19107/part-categories?carType=PC&search=filtre')->toArray();
        $upper = $client->request('GET', '/cars/19107/part-categories?carType=PC&search=FILTRE')->toArray();

        $this->assertSame($lower['hydra:totalItems'], $upper['hydra:totalItems']);
        $this->assertSame(1, $upper['hydra:totalItems']);
    }

    public function testArticlesForCategory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/1446/part-categories/200136/articles?carType=CV');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'hydra:totalItems' => 1,
            'hydra:member' => [
                ['number' => 'W 712/73', 'brand' => 'MANN-FILTER', 'productGroup' => 'Filtre à huile'],
            ],
        ]);
    }

    public function testArticlesFallBackToTheDeclaredCarTypeDefault(): void
    {
        $client = static::createClient();
        // No ?carType: the value must come from the declared QueryParameter's schema default (PC).
        // A wrong carType finds no such car among the other kind's types and comes back empty, not 404.
        $response = $client->request('GET', '/cars/19107/part-categories/100259/articles');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertSame(116, $data['hydra:totalItems']);
        $this->assertSame('Filtre à huile', $data['hydra:member'][0]['productGroup']);
    }

    public function testInvalidCarTypeIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/19107/part-categories?carType=NOPE');

        $this->assertResponseStatusCodeSame(422);
    }
}
