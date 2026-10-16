<?php

declare(strict_types=1);

namespace App\Tests\Functional\Store;

use App\Motul\MotulResponseStore;
use App\Tests\Functional\AppTestCase;

final class MotulResponseStoreTest extends AppTestCase
{
    public function testMissThenHit(): void
    {
        $store = static::getContainer()->get(MotulResponseStore::class);
        $this->assertNull($store->find('recs.abc'));

        $store->save('recs.abc', ['html' => '<html>é</html>']);

        $this->assertSame(['html' => '<html>é</html>'], $store->find('recs.abc'));
    }
}
