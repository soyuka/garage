<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Catalog\ArticlePhoto;
use App\Catalog\ArticlePhotoStore;
use App\Entity\ArticlePhotoFile;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Binary;

final class ArticlePhotoStoreTest extends AppTestCase
{
    public function testBytesRoundTripAsBinData(): void
    {
        $store = static::getContainer()->get(ArticlePhotoStore::class);
        $url = 'https://img.example.com/a.jpg';
        $bytes = "\xFF\xD8\xFF\x00\x9D not utf-8 \x00";
        $this->assertNull($store->find($url));

        $store->save($url, new ArticlePhoto('image/jpeg', $bytes));

        $this->assertEquals(new ArticlePhoto('image/jpeg', $bytes), $store->find($url));
        $raw = static::getContainer()->get(DocumentManager::class)->getDocumentCollection(ArticlePhotoFile::class)
            ->findOne(['_id' => 'photo.'.sha1($url)], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $this->assertInstanceOf(Binary::class, $raw['bytes']);
        $this->assertSame($bytes, $raw['bytes']->getData());
        $this->assertSame($url, $raw['url']);
        $this->assertSame('image/jpeg', $raw['mimeType']);
    }
}
