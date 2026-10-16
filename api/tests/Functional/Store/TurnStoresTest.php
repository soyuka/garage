<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Agent\Finder\TurnImage;
use App\Agent\Finder\TurnImageStash;
use App\Agent\MongoTurnCancellations;
use App\Agent\TurnCancellations;
use App\Entity\TurnCancellation;
use App\Entity\TurnImageBatch;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Clock\MockClock;

final class TurnStoresTest extends AppTestCase
{
    public function testImagesRoundTripAsBinDataWithAnExpiry(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-10-16 12:00:00');
        $stash = new TurnImageStash(self::dm(), $clock);
        $bytes = "\x89PNG\r\n\x1A\n\x00binary";

        $key = $stash->put([new TurnImage('image/png', $bytes), new TurnImage('image/jpeg', 'jpg')]);

        $this->assertEquals([new TurnImage('image/png', $bytes), new TurnImage('image/jpeg', 'jpg')], $stash->get($key));
        $raw = self::raw(TurnImageBatch::class, $key);
        $this->assertInstanceOf(Binary::class, $raw['images'][0]['bytes']);
        $this->assertSame($bytes, $raw['images'][0]['bytes']->getData());
        $this->assertSame('2026-10-16T12:15:00+00:00', $raw['expiresAt']->toDateTime()->format(\DATE_ATOM));

        $clock->sleep(901);
        $this->assertSame([], $stash->get($key));
    }

    public function testForgetDropsTheImages(): void
    {
        $stash = static::getContainer()->get(TurnImageStash::class);
        $key = $stash->put([new TurnImage('image/png', 'x')]);

        $stash->forget($key);

        $this->assertSame([], $stash->get($key));
    }

    public function testCancellationIsSharedThroughMongoAndExpires(): void
    {
        self::bootKernel();
        $this->assertInstanceOf(MongoTurnCancellations::class, static::getContainer()->get(TurnCancellations::class));
        $clock = new MockClock('2026-10-16 12:00:00');
        $cancellations = new MongoTurnCancellations(self::dm(), $clock);

        $this->assertFalse($cancellations->isRequested('sess-1'));
        $cancellations->request('sess-1');
        $this->assertTrue($cancellations->isRequested('sess-1'));
        $this->assertFalse($cancellations->isRequested('sess-2'));

        $docs = self::dm()->getDocumentCollection(TurnCancellation::class)->find([], ['typeMap' => ['root' => 'array']])->toArray();
        $this->assertCount(1, $docs);
        $this->assertInstanceOf(UTCDateTime::class, $docs[0]['expiresAt']);

        $clock->sleep(121);
        $this->assertFalse($cancellations->isRequested('sess-1'));

        $cancellations->request('sess-1');
        $cancellations->clear('sess-1');
        $this->assertFalse($cancellations->isRequested('sess-1'));
    }

    public function testShortLivedCollectionsDeclareTtlIndexes(): void
    {
        self::bootKernel();
        foreach ([TurnImageBatch::class, TurnCancellation::class] as $class) {
            $ttl = array_filter(self::dm()->getClassMetadata($class)->getIndexes(), static fn (array $i): bool => ['expiresAt' => 1] === $i['keys'] && 0 === ($i['options']['expireAfterSeconds'] ?? null));
            $this->assertCount(1, $ttl, $class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(string $class, string $id): array
    {
        return self::dm()->getDocumentCollection($class)->findOne(['_id' => $id], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    private static function dm(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }
}
