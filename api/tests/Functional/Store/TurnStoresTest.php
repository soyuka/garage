<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Agent\MongoTurnCancellations;
use App\Agent\TurnCancellations;
use App\Entity\TurnCancellation;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Clock\MockClock;

final class TurnStoresTest extends AppTestCase
{
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
        foreach ([TurnCancellation::class] as $class) {
            $ttl = array_filter(self::dm()->getClassMetadata($class)->getIndexes(), static fn (array $i): bool => ['expiresAt' => 1] === $i['keys'] && 0 === ($i['options']['expireAfterSeconds'] ?? null));
            $this->assertCount(1, $ttl, $class);
        }
    }

    private static function dm(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }
}
