<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\RateLimiter\MongoRateLimiterStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\MongoDbStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * The php container, the messenger worker and any replica each have their own /app/var, so a limiter kept in
 * cache.app (filesystem) multiplies every quota by the number of containers. State and locks live in MongoDB,
 * which they all share.
 */
final class RateLimiterStorageTest extends AppTestCase
{
    public function testEveryLimiterOutsideTheSuiteKeepsItsStateInMongo(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/rate_limiter.yaml');
        $this->assertIsArray($config);
        $limiters = $config['framework']['rate_limiter'] ?? [];
        $this->assertIsArray($limiters);
        $this->assertNotEmpty($limiters);

        foreach ($limiters as $name => $limiter) {
            $this->assertSame(MongoRateLimiterStorage::class, $limiter['storage_service'] ?? null, (string) $name);
        }
    }

    public function testTwoContainersShareOneBucket(): void
    {
        static::createClient();
        $documents = static::getContainer()->get(DocumentManager::class);
        $locks = static::getContainer()->get(LockFactory::class);
        $config = ['id' => 'shared', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'];
        $php = new RateLimiterFactory($config, new MongoRateLimiterStorage($documents), $locks);
        $worker = new RateLimiterFactory($config, new MongoRateLimiterStorage($documents), $locks);

        $this->assertTrue($php->create('owner@example.com')->consume(2)->isAccepted());
        $this->assertSame(1, $worker->create('owner@example.com')->consume(0)->getRemainingTokens());
        $this->assertTrue($worker->create('owner@example.com')->consume()->isAccepted());
        $this->assertFalse($php->create('owner@example.com')->consume()->isAccepted());
        $this->assertTrue($php->create('someone@example.com')->consume()->isAccepted());
    }

    public function testTheLockIsSharedToo(): void
    {
        static::createClient();
        $collection = static::getContainer()->get('app.lock.collection');
        $this->assertInstanceOf(Collection::class, $collection);

        $lock = static::getContainer()->get(LockFactory::class)->createLock('shared-lock');
        $this->assertTrue($lock->acquire());
        $this->assertFalse((new LockFactory(new MongoDbStore($collection)))->createLock('shared-lock')->acquire());
        $lock->release();
        $this->assertTrue((new LockFactory(new MongoDbStore($collection)))->createLock('shared-lock')->acquire());
    }
}
