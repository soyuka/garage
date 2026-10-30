<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class VinDecodeTest extends AppTestCase
{
    use McpClientTrait;

    public function testGetDecodesAEuropeanVin(): void
    {
        $client = static::createClient();
        $body = $client->request('GET', '/vins/vf33ckfwb83471925')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertSame('VF33CKFWB83471925', $body['vin']);
        $this->assertTrue($body['valid']);
        $this->assertNull($body['error']);
        $this->assertSame('VF3', $body['wmi']);
        $this->assertSame('3CKFWB', $body['vds']);
        $this->assertSame('83471925', $body['vis']);
        $this->assertSame('Peugeot', $body['manufacturer']);
        $this->assertSame('France', $body['country']);
        $this->assertSame('Europe', $body['region']);
        $this->assertNull($body['modelYear'], 'a 2004 307: position 10 is not a model year on European VINs');
        $this->assertSame([2008], $body['modelYearCodeCandidates']);
        $this->assertNull($body['checksumValid'], 'check digit is only mandatory in North America');
    }

    public function testAnonymousGetIsRejected(): void
    {
        static::createAnonymousClient()->request('GET', '/vins/VF33CKFWB83471925');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetValidatesTheNorthAmericanCheckDigit(): void
    {
        $client = static::createClient();

        $good = $client->request('GET', '/vins/1M8GDM9AXKP042788')->toArray();
        $bad = $client->request('GET', '/vins/1M8GDM9A1KP042788')->toArray();

        $this->assertSame(2019, $good['modelYear']);
        $this->assertSame([], $good['modelYearCodeCandidates']);
        $this->assertTrue($good['checksumValid']);
        $this->assertFalse($bad['checksumValid']);
    }

    public function testGetReportsAnInvalidVinWithoutFailing(): void
    {
        $client = static::createClient();
        $body = $client->request('GET', '/vins/NOT-A-VIN')->toArray();

        $this->assertResponseIsSuccessful();
        $this->assertFalse($body['valid']);
        $this->assertNotEmpty($body['error']);
        $this->assertNull($body['wmi']);
        $this->assertNull($body['checksumValid']);
    }

    public function testMcpToolDecodesAVin(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'decode_vin',
            'arguments' => ['vin' => 'VF33CKFWB83471925'],
        ], 2);

        $content = $result['result']['structuredContent'];
        $this->assertTrue($content['valid']);
        $this->assertSame('VF33CKFWB83471925', $content['vin']);
        $this->assertSame('Peugeot', $content['manufacturer']);
    }

    public function testMcpToolReportsAnInvalidVin(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'decode_vin',
            'arguments' => ['vin' => 'short'],
        ], 2);

        $this->assertFalse($result['result']['structuredContent']['valid']);
    }

    public function testMcpToolDescriptionWarnsAboutKTypeAndModelYear(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $list = $this->call($client, $sessionId, 'tools/list', [], 2);
        $tool = current(array_filter($list['result']['tools'], static fn (array $t) => 'decode_vin' === $t['name']));

        $this->assertNotFalse($tool, 'decode_vin must be advertised');
        $this->assertStringContainsString('kType', $tool['description']);
        $this->assertStringContainsString('lookup_plate', $tool['description']);
        $this->assertStringContainsString('model year', $tool['description']);
        $this->assertStringContainsString('Outside North America', $tool['description']);
    }
}
