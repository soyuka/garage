<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Stream;

use App\Mcp\Stream\PendingCall;
use App\Mcp\Stream\PendingCallStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PendingCallStoreTest extends TestCase
{
    private const string TOPIC = 'https://garage.soyuka.me/mcp/streams/01JH2X';

    public function testHoldsACallBetweenTheTwoRequests(): void
    {
        $store = new PendingCallStore(new ArrayAdapter());
        $call = new PendingCall('{"id":1}', ['Accept' => 'text/event-stream'], 'owner@example.com');

        $store->save(self::TOPIC, $call);
        $taken = $store->take(self::TOPIC);

        $this->assertNotNull($taken);
        $this->assertSame('{"id":1}', $taken->body);
        $this->assertSame('owner@example.com', $taken->owner);
    }

    /**
     * A replayed lifecycle callback must not run the same tool call a second time.
     */
    public function testACallCanOnlyBeTakenOnce(): void
    {
        $store = new PendingCallStore(new ArrayAdapter());
        $store->save(self::TOPIC, new PendingCall('{}', [], null));

        $this->assertNotNull($store->take(self::TOPIC));
        $this->assertNull($store->take(self::TOPIC));
    }

    public function testAnUnknownTopicHoldsNothing(): void
    {
        $this->assertNull((new PendingCallStore(new ArrayAdapter()))->take(self::TOPIC));
    }

    /**
     * The topic is a URL and PSR-6 forbids most of its characters, so the key has to be derived.
     */
    public function testTopicsThatArentValidCacheKeysStillRoundTrip(): void
    {
        $store = new PendingCallStore(new ArrayAdapter());
        $topic = 'https://host/mcp/streams/{a}@b:c()/d';

        $store->save($topic, new PendingCall('{"id":2}', [], null));

        $this->assertSame('{"id":2}', $store->take($topic)?->body);
    }
}
