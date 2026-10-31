<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class NarrowedPartsTest extends AppTestCase
{
    use McpClientTrait;

    public function testNarrowedRouteGroupsArticlesIntoPhysicalParts(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/cars/19107/part-categories/100259/narrowed');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertTrue($data['narrowed']);
        $this->assertNull($data['degradedReason']);
        $this->assertSame(116, $data['totalArticles']);
        $this->assertSame(87, $data['totalBrands']);
        $this->assertCount(2, $data['clusters']);
        $this->assertSame(['hauteur'], array_column($data['differsOn'], 'key'));

        $part = $data['clusters'][0];
        $this->assertSame('c1', $part['id']);
        $this->assertSame('MAHLE', $part['representative']['brand']);
        $this->assertArrayNotHasKey('imageUrl', $part['representative']);
        $this->assertSame('93.5', $part['fingerprint']['hauteur']['value']);
        $this->assertSame(85, $part['fingerprint']['hauteur']['min']);
        $this->assertContains(['brand' => 'AMC', 'number' => 'MO-534'], $part['oeNumbers']);
        $this->assertSame(116, array_sum(array_column($data['clusters'], 'articleCount')) + $data['unclassifiedCount']);
        $this->assertLessThan(110, $data['unclassifiedCount']);
        $this->assertSame('vehicle', $data['applicabilityScope']);

        $conditions = array_column($data['clusters'][1]['applicability'], 'values', 'name');
        $this->assertSame(['176451'], $conditions['Nº de châssis à partir de']);

        $this->assertSame(0, $data['upstreamCalls'], 'the catalogue is local: nothing is bought upstream');
    }

    public function testNarrowedRouteRejectsAnUnknownCarType(): void
    {
        static::createClient()->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=XX');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNarrowPartsToolReturnsTheSamePayloadAsTheRoute(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'narrow_parts',
            'arguments' => ['carId' => 19107, 'carType' => 'PC', 'categoryId' => 100259, 'make' => 'MAZDA'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame('ok', $content['status']);
        $this->assertTrue($content['narrowed']);
        $this->assertCount(2, $content['clusters']);
        $this->assertSame(['hauteur'], array_column($content['differsOn'], 'key'));
        $this->assertSame(116, $content['totalArticles']);
        $this->assertSame('vehicle', $content['applicabilityScope']);
        $this->assertNotSame([], $content['clusters'][1]['applicability']);
    }

    public function testAMixedCategoryListsItsProductGroupsWithoutNarrowing(): void
    {
        $data = static::createClient()->request('GET', '/cars/19107/part-categories/100198/narrowed?make=MAZDA')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['groupRequired']);
        $this->assertSame([], $data['clusters']);
        $this->assertSame([51, 914, 2066], array_column($data['productGroups'], 'id'));
        $this->assertSame('Rotule de direction intérieure, barre de connexion', $data['productGroups'][0]['name']);
        $this->assertSame(0, $data['upstreamCalls']);
    }

    public function testTheChosenProductGroupIsNarrowedAlone(): void
    {
        $data = static::createClient()->request('GET', '/cars/19107/part-categories/100198/narrowed?make=MAZDA&productGroupId=51')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertFalse($data['groupRequired']);
        $this->assertSame(['id' => 51, 'name' => 'Rotule de direction intérieure, barre de connexion'], $data['productGroup']);
        $this->assertSame(2, $data['totalArticles']);
    }

    public function testNarrowPartsToolTakesTheProductGroup(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'narrow_parts',
            'arguments' => ['carId' => 19107, 'carType' => 'PC', 'categoryId' => 100198, 'make' => 'MAZDA', 'productGroupId' => 51],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertSame(51, $content['productGroup']['id']);
        $this->assertSame(2, $content['totalArticles']);
    }

    public function testNarrowPartsToolSteersTheAgentAwayFromListArticles(): void
    {
        $client = static::createClient();
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
