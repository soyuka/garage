<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Entity\SivLookup;
use App\Siv\MongoSivLookupStore;
use App\Siv\SivLookupStore;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;

final class SivLookupStoreTest extends AppTestCase
{
    public function testMissThenHitWithTheEnvelopeStoredNatively(): void
    {
        $store = static::getContainer()->get(SivLookupStore::class);
        $this->assertInstanceOf(MongoSivLookupStore::class, $store);
        $this->assertNull($store->find('AB123CD'));

        $envelope = ['code_erreur' => 200, 'data' => ['marque' => 'RENAULT', 'puisFisc' => 6]];
        $store->save('AB123CD', $envelope);

        $this->assertSame($envelope, $store->find('AB123CD'));
        $raw = static::getContainer()->get(DocumentManager::class)->getDocumentCollection(SivLookup::class)
            ->findOne(['_id' => 'AB123CD'], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        $this->assertSame($envelope, $raw['envelope']);
        $this->assertInstanceOf(UTCDateTime::class, $raw['fetchedAt']);
    }
}
