<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class NarrowedPartsTest extends AppTestCase
{
    use McpClientTrait;

    public function testNarrowedRouteGroupsArticlesIntoPhysicalParts(): void
    {
        $client = static::createClient();
        static::catalogue();
        $response = $client->request('GET', '/cars/19107/part-categories/100259/narrowed?make=MAZDA');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertTrue($data['narrowed']);
        $this->assertNull($data['degradedReason'] ?? null);
        $this->assertSame(220, $data['totalArticles']);
        $this->assertSame(126, $data['totalBrands']);
        $this->assertCount(1, $data['clusters']);
        $this->assertSame([], $data['differsOn']);

        $part = $data['clusters'][0];
        $this->assertSame('c1', $part['id']);
        $this->assertSame('MANN-FILTER', $part['representative']['brand']);
        $this->assertArrayNotHasKey('imageUrl', $part['representative']);
        $this->assertSame('93', $part['fingerprint']['hauteur']['value']);
        $this->assertSame(85, $part['fingerprint']['hauteur']['min']);
        $this->assertContains(['brand' => 'MAZDA', 'number' => 'LF10-14-302'], $part['oeNumbers']);
        $this->assertSame(220, $part['articleCount'] + $data['unclassifiedCount']);
        $this->assertLessThan(110, $data['unclassifiedCount']);
        $this->assertSame('article', $data['applicabilityScope']);
        $this->assertContains('130831', array_column($part['applicability'], 'values', 'name')['Numéro de châssis (VIN) à partir de'] ?? []);
    }

    public function testNarrowedRouteRejectsAnUnknownCarType(): void
    {
        $client = static::createClient();
        static::catalogue();
        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=XX');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNarrowPartsToolReturnsTheSamePayloadAsTheRoute(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'narrow_parts',
            'arguments' => ['carId' => 19107, 'carType' => 'PC', 'categoryId' => 100259, 'make' => 'MAZDA'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertTrue($content['narrowed']);
        $this->assertCount(1, $content['clusters']);
        $this->assertSame([], $content['differsOn']);
        $this->assertSame(220, $content['totalArticles']);
        $this->assertSame('article', $content['applicabilityScope']);
        $this->assertNotSame([], $content['clusters'][0]['applicability']);
    }

    public function testAMixedCategoryListsItsProductGroupsWithoutNarrowing(): void
    {
        $client = static::createClient();
        static::catalogue();
        $data = $client->request('GET', '/cars/16182/part-categories/100198/narrowed?make=PEUGEOT')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['groupRequired']);
        $this->assertSame([], $data['clusters']);
        $this->assertSame([914, 51, 2066], array_column($data['productGroups'], 'id'));
        $this->assertSame('Rotule de direction intérieure, barre de connexion', $data['productGroups'][1]['name']);
    }

    public function testTheChosenProductGroupIsNarrowedAlone(): void
    {
        $client = static::createClient();
        static::catalogue();
        $data = $client->request('GET', '/cars/16182/part-categories/100198/narrowed?make=PEUGEOT&productGroupId=51')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertFalse($data['groupRequired']);
        $this->assertSame(['id' => 51, 'name' => 'Rotule de direction intérieure, barre de connexion'], $data['productGroup']);
        $this->assertSame(32, $data['totalArticles']);
    }

    public function testNarrowPartsToolTakesTheProductGroup(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'narrow_parts',
            'arguments' => ['carId' => 16182, 'carType' => 'PC', 'categoryId' => 100198, 'make' => 'PEUGEOT', 'productGroupId' => 51],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame(51, $content['productGroup']['id']);
        $this->assertSame(32, $content['totalArticles']);
    }

    public function testNarrowPartsToolSteersTheAgentAwayFromListArticles(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $listed = $this->call($client, $sessionId, 'tools/list', [], 2);
        $tool = array_column($listed['result']['tools'], null, 'name')['narrow_parts'] ?? null;

        $this->assertNotNull($tool);
        $this->assertStringContainsString('list_articles', $tool['description']);
        $this->assertStringContainsString('differsOn', $tool['description']);
        $this->assertStringContainsString('VIN', $tool['description']);
        $this->assertStringContainsString('productGroupId', $tool['description']);
    }
}
