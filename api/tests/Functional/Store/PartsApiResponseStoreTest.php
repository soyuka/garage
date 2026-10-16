<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Entity\PartsApiResponse;
use App\PartsApi\MongoPartsApiResponseStore;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Clock\MockClock;

final class PartsApiResponseStoreTest extends AppTestCase
{
    private MockClock $clock;
    private MongoPartsApiResponseStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->clock = new MockClock('2026-10-16 12:00:00');
        $this->store = new MongoPartsApiResponseStore(self::dm(), $this->clock);
    }

    public function testMissThenHit(): void
    {
        $this->assertNull($this->store->find('getMakes.abc'));

        $this->store->save('getMakes.abc', 'getMakes', ['lang' => 6], [['makeId' => 93, 'makeName' => 'RENAULT']], null);

        $this->assertSame([['makeId' => 93, 'makeName' => 'RENAULT']], $this->store->find('getMakes.abc'));
    }

    public function testStoresMethodParamsAndNativeData(): void
    {
        $this->store->save('getCars.k', 'getCars', ['modelId' => 7, 'carType' => 'PC'], [['carId' => 1, 'nested' => ['a' => 1.5]]], null);

        $raw = $this->raw('getCars.k');
        $this->assertSame('getCars', $raw['method']);
        $this->assertSame(['modelId' => 7, 'carType' => 'PC'], $raw['params']);
        $this->assertSame([['carId' => 1, 'nested' => ['a' => 1.5]]], $raw['data']);
        $this->assertNull($raw['dataJson']);
        $this->assertNull($raw['expiresAt']);
        $this->assertInstanceOf(UTCDateTime::class, $raw['fetchedAt']);
    }

    public function testTtlSetsExpiresAtAndExpiredEntriesMiss(): void
    {
        $this->store->save('getArticles.k', 'getArticles', [], [['id' => 1]], 100);

        $raw = $this->raw('getArticles.k');
        $this->assertInstanceOf(UTCDateTime::class, $raw['expiresAt']);
        $this->assertSame('2026-10-16T12:01:40+00:00', $raw['expiresAt']->toDateTime()->format(\DATE_ATOM));

        $this->clock->sleep(99);
        $this->assertSame([['id' => 1]], $this->store->find('getArticles.k'));

        $this->clock->sleep(2);
        $this->assertNull($this->store->find('getArticles.k'), 'read must not trust the TTL monitor, which runs once a minute');
    }

    public function testFindManyReturnsHitsOnlyKeyedById(): void
    {
        $this->store->save('getArticles.a', 'getArticles', [], [['id' => 1]], null);
        $this->store->save('getArticles.b', 'getArticles', [], [['id' => 2]], 10);
        $this->clock->sleep(11);

        $this->assertSame(['getArticles.a' => [['id' => 1]]], $this->store->findMany(['getArticles.a', 'getArticles.b', 'getArticles.c']));
    }

    public function testSavingAgainReplacesTheEntry(): void
    {
        $this->store->save('getArticles.k', 'getArticles', [], [['id' => 1]], 10);
        $this->store->save('getArticles.k', 'getArticles', [], [['id' => 2]], null);

        $this->clock->sleep(11);
        $this->assertSame([['id' => 2]], $this->store->find('getArticles.k'));
    }

    public function testDottedAndDollarKeysRoundTripNatively(): void
    {
        $data = [['a.b' => 1, '$ref' => 'x', 'list' => [['c.d' => [1, 2]]]]];
        $this->store->save('getArticleCriteria.k', 'getArticleCriteria', [], $data, null);

        $this->assertSame($data, $this->store->find('getArticleCriteria.k'));
        $this->assertSame($data, $this->raw('getArticleCriteria.k')['data']);
    }

    public function testAnEmptyKeyFallsBackToJsonInsteadOfLosingTheResponse(): void
    {
        $data = [['' => 'blank', 'x' => 1]];
        $this->store->save('getArticleCriteria.e', 'getArticleCriteria', [], $data, null);

        $this->assertSame($data, $this->store->find('getArticleCriteria.e'));
        $raw = $this->raw('getArticleCriteria.e');
        $this->assertNull($raw['data']);
        $this->assertSame($data, json_decode($raw['dataJson'], true));
    }

    public function testDeclaresTheTtlIndex(): void
    {
        $this->assertContains(
            ['keys' => ['expiresAt' => 1], 'options' => ['expireAfterSeconds' => 0]],
            array_map(static fn (array $i): array => ['keys' => $i['keys'], 'options' => array_intersect_key($i['options'], ['expireAfterSeconds' => 0])], self::dm()->getClassMetadata(PartsApiResponse::class)->getIndexes()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(string $id): array
    {
        return self::dm()->getDocumentCollection(PartsApiResponse::class)->findOne(['_id' => $id], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    private static function dm(): DocumentManager
    {
        return static::getContainer()->get(DocumentManager::class);
    }
}
