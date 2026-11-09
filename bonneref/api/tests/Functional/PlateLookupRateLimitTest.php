<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class PlateLookupRateLimitTest extends AppTestCase
{
    use McpClientTrait;

    public function testAPaidLookupOverTheOwnersLimitIs429WithRetryAfter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::exhaust(self::TEST_OWNER);

        $response = $client->request('GET', '/vehicles/XC302ZC');

        $this->assertResponseStatusCodeSame(429);
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        $this->assertNotNull($retryAfter, '429 carries Retry-After');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertNull(self::sivStore()->find('XC302ZC'), 'the paid upstream was never called');
    }

    public function testAStoredPlateCostsNothingAndIsServedOverTheLimit(): void
    {
        $envelope = json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/siv/XC302ZC.json'), true);
        self::sivStore()->save('XC302ZC', $envelope);

        $client = static::createClient();
        $client->disableReboot();
        self::exhaust(self::TEST_OWNER);

        $client->request('GET', '/vehicles/XC302ZC');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['make' => 'IVECO']);
    }

    public function testTheLimitIsPerOwner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::exhaust(self::TEST_OWNER);

        $client->request('GET', '/vehicles/XC302ZC', [
            'headers' => self::authHeaders('someone-else', 'someone-else@example.com'),
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testTheMcpLookupPlateToolSharesTheLimitAndAnswersAStructuredResult(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $sessionId = $this->initialize($client);
        self::exhaust(self::TEST_OWNER);

        // call() fails on a JSON-RPC error: that would hide the limit from the model.
        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'XC302ZC'],
        ], 2));

        $this->assertSame('rate_limited', $result['error']);
        $this->assertMatchesRegularExpression('/try again in about [1-9]\d* minutes; do not retry now/', $result['message']);
    }

    private static function exhaust(string $owner): void
    {
        $factory = static::getContainer()->get('limiter.plate_lookup');
        $limiter = $factory->create($owner);
        for ($i = 0; $i < 10_000 && $limiter->consume()->isAccepted(); ++$i) {
        }
    }
}
