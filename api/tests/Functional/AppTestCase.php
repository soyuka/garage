<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Cache\CacheItemPoolInterface;

abstract class AppTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /**
     * The garage lives in MongoDB, which has no DAMA-style transactional rollback. Purge every
     * mapped collection before each test so writes from one test never leak into the next — the
     * Mongo counterpart of the rolled-back DBAL transaction the cache pools get for free.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dm = static::getContainer()->get(DocumentManager::class);
        foreach ($dm->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedDocument) {
                continue;
            }
            $dm->getDocumentCollection($metadata->getName())->deleteMany([]);
        }
        $dm->clear();
    }

    /**
     * The two upstream caches now live in PostgreSQL; DAMA wraps every test in a
     * rolled-back transaction, so cache rows written by one test never leak into
     * the next — no manual wipe needed.
     */
    protected static function sivCache(): CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.siv');
    }

    protected static function partsApiCache(): CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.partsapi');
    }
}
