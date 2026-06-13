<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Psr\Cache\CacheItemPoolInterface;

abstract class AppTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

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
