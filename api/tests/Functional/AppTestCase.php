<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use MongoDB\Client;
use Psr\Cache\CacheItemPoolInterface;

abstract class AppTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /**
     * The garage lives in MongoDB, which has no DAMA-style transactional rollback. Drop the test
     * database before each test so writes from one test never leak into the next — the Mongo
     * counterpart of the rolled-back DBAL transaction the cache pools get for free.
     *
     * Done through a standalone driver client, NOT the app's DocumentManager: booting the kernel
     * here (static::getContainer()) before the test's own createClient() corrupts the BrowserKit
     * kernel lifecycle and writes are lost. A raw client keeps isolation fully decoupled from the
     * kernel under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $url = $_ENV['MONGODB_URL'] ?? $_SERVER['MONGODB_URL'];
        $db = $_ENV['MONGODB_DB'] ?? $_SERVER['MONGODB_DB'];
        (new Client($url))->dropDatabase($db);
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
