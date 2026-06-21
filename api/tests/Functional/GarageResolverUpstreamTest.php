<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Garage\GarageResolver;
use App\Garage\VehicleType;

/**
 * resolve() write-through must degrade like backfillCatalogSpecs: a partsapi hiccup on the
 * fill-volume fetch is a catalog MISS (return null → not_in_catalog), never a 500 on the read.
 */
final class GarageResolverUpstreamTest extends AppTestCase
{
    public function testResolveSwallowsUpstreamFailureAndReturnsNull(): void
    {
        static::createClient();
        $resolver = static::getContainer()->get(GarageResolver::class);

        // carId 500200 makes the partsapi fixture answer HTTP 401. The vehicle misses the garage,
        // so resolve() falls through to the fill-volume write-through, which hits that 401.
        $vehicle = $resolver->resolve('NOSUCHMAKE', 'NOSUCHMODEL', null, VehicleType::Car, 500200);

        $this->assertNull($vehicle);
    }
}
