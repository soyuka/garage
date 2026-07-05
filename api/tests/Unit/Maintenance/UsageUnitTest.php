<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Garage\UsageUnit as GarageUsageUnit;
use App\Maintenance\UsageUnit;
use PHPUnit\Framework\TestCase;

/**
 * Pins the by-case (never by backing string) conversion between the log-side {@see UsageUnit}, which
 * spells engine hours `hours`, and the garage-side {@see GarageUsageUnit}, which spells them `h`. The
 * two enums diverge on the wire on purpose, so the hand-written match arms in toGarageUnit()/
 * fromGarageUnit() are the only bridge — a silent drift in either would misfile a usage reading.
 */
final class UsageUnitTest extends TestCase
{
    public function testEveryLogUnitRoundTripsThroughGarage(): void
    {
        foreach (UsageUnit::cases() as $unit) {
            self::assertSame($unit, UsageUnit::fromGarageUnit($unit->toGarageUnit()));
        }
    }

    public function testEveryGarageUnitRoundTripsThroughLog(): void
    {
        foreach (GarageUsageUnit::cases() as $unit) {
            self::assertSame($unit, UsageUnit::fromGarageUnit($unit)->toGarageUnit());
        }
    }
}
