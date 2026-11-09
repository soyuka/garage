<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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

    public function testTheGlobalLimitRefusesEveryOwner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::exhaust('global', 'limiter.plate_lookup_global');

        $client->request('GET', '/vehicles/XC302ZC', [
            'headers' => self::authHeaders('someone-else', 'someone-else@example.com'),
        ]);

        $this->assertResponseStatusCodeSame(429);
        $this->assertNull(self::sivStore()->find('XC302ZC'), 'the paid upstream was never called');
    }

    public function testANonFrenchPlateIsRefusedBeforeTheQuotaAndTheUpstream(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::exhaust(self::TEST_OWNER);

        $client->request('GET', '/vehicles/ABCDEFGH');

        $this->assertResponseStatusCodeSame(422, 'a malformed plate is a client error, not a spent lookup');
        $this->assertNull(self::sivStore()->find('ABCDEFGH'));
    }

    public function testThePlateRouteOnlyMatchesPlateCharacters(): void
    {
        static::createClient()->request('GET', '/vehicles/'.str_repeat('A', 17));

        $this->assertResponseStatusCodeSame(404);
        $this->assertNull(self::sivStore()->find(str_repeat('A', 17)));
    }

    public function testTheMcpToolRefusesANonFrenchPlateAsInvalidInput(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $sessionId = $this->initialize($client);
        self::exhaust(self::TEST_OWNER);

        $result = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'lookup_plate',
            'arguments' => ['plate' => 'ABCDEFGH'],
        ], 2));

        $this->assertSame('invalid_input', $result['error']);
    }

    private static function exhaust(string $owner, string $limiter = 'limiter.plate_lookup'): void
    {
        $factory = static::getContainer()->get($limiter);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create($owner);
        for ($i = 0; $i < 10_000; ++$i) {
            if (!$limiter->consume()->isAccepted()) {
                break;
            }
        }
    }
}
