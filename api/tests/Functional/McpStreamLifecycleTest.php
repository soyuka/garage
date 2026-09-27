<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The endpoint Caddy calls once the hub has accepted a delegated stream's subscription.
 *
 * It is deliberately outside the `mcp` firewall: Caddy talks to it over the compose network with a
 * shared token, not with an owner's bearer. That makes the token the whole gate, so it is the thing
 * worth pinning.
 */
final class McpStreamLifecycleTest extends AppTestCase
{
    private const string PATH = '/_mcp/stream-lifecycle';
    private const string TOKEN = 'test-lifecycle-token';
    private const string TOPIC = 'https://localhost/mcp/streams/unknown';

    public function testRefusesACallWithNoToken(): void
    {
        $client = static::createClient();

        $client->request('POST', self::PATH, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['topic' => self::TOPIC, 'state' => 'ready'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRefusesACallWithTheWrongToken(): void
    {
        $client = static::createClient();

        $client->request('POST', self::PATH, [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer not-the-token'],
            'json' => ['topic' => self::TOPIC, 'state' => 'ready'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRefusesANotificationWithNoTopic(): void
    {
        $client = static::createClient();

        $client->request('POST', self::PATH, [
            'headers' => self::lifecycleHeaders(),
            'json' => ['state' => 'ready'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * 204 exactly, not any 2xx: the Caddy handler compares the status to http.StatusNoContent and
     * treats everything else as a failed callback, which aborts the stream with a 502
     * (mercure-stream-delegation delegate.go:264).
     *
     * An unknown topic is the normal shape of a late or duplicated callback: the call has already
     * been taken, so there is nothing left to run and nothing to report.
     */
    public function testAcceptsAReadyForATopicItNoLongerHolds(): void
    {
        $client = static::createClient();

        $client->request('POST', self::PATH, [
            'headers' => self::lifecycleHeaders(),
            'json' => ['topic' => self::TOPIC, 'state' => 'ready'],
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    public function testAcceptsTheTerminalStates(): void
    {
        $client = static::createClient();

        foreach (['complete', 'cancelled'] as $state) {
            $client->request('POST', self::PATH, [
                'headers' => self::lifecycleHeaders(),
                'json' => ['topic' => self::TOPIC, 'state' => $state],
            ]);

            $this->assertResponseStatusCodeSame(204);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function lifecycleHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Authorization' => 'Bearer '.self::TOKEN];
    }
}
