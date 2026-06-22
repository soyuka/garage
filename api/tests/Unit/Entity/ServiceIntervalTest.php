<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ServiceInterval;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;
use PHPUnit\Framework\TestCase;

final class ServiceIntervalTest extends TestCase
{
    public function testKeyCombinesTypeActionAndComponentSoTwoCadencesOfOneTypeCoexist(): void
    {
        $foam = new ServiceInterval('air_filter', MaintenanceAction::Clean, 'web', component: 'foam element');
        $paper = new ServiceInterval('air_filter', MaintenanceAction::Replace, 'web', component: 'paper cartridge');

        // Same type, different action+component → distinct keys (both survive a keyed 'set' map).
        $this->assertNotSame($foam->key(), $paper->key());
        $this->assertSame('air_filter:clean:foam element', $foam->key());
    }

    public function testKeyOmitsComponentWhenAbsent(): void
    {
        $oil = new ServiceInterval('oil_change', MaintenanceAction::Change, 'web');

        $this->assertSame('oil_change:change', $oil->key());
    }

    public function testActionAloneSeparatesInspectFromReplaceOnOneType(): void
    {
        $check = new ServiceInterval('spark_plug', MaintenanceAction::Inspect, 'web');
        $swap = new ServiceInterval('spark_plug', MaintenanceAction::Replace, 'web');

        $this->assertSame('spark_plug:inspect', $check->key());
        $this->assertSame('spark_plug:replace', $swap->key());
    }

    public function testWebSourceIsUnverifiedAuthoritativeSourceIsVerified(): void
    {
        $web = new ServiceInterval('oil_change', MaintenanceAction::Change, 'web');
        $catalog = ServiceInterval::fromSource('oil_change', MaintenanceAction::Change, 'partsapi');

        $this->assertFalse($web->isVerified(), 'A Gemini/web-sourced interval is advisory, not authoritative.');
        $this->assertTrue($catalog->isVerified());
    }

    public function testCarriesUsageAndTimeCadenceWithBreakInOverride(): void
    {
        $oil = new ServiceInterval(
            type: 'oil_change',
            action: MaintenanceAction::Change,
            source: 'web',
            everyUsageValue: 50,
            everyUsageUnit: UsageUnit::Hours,
            everyMonths: 12,
            firstUsageValue: 5,
        );

        $this->assertSame(50, $oil->getEveryUsageValue());
        $this->assertSame(UsageUnit::Hours, $oil->getEveryUsageUnit());
        $this->assertSame(12, $oil->getEveryMonths());
        $this->assertSame(5, $oil->getFirstUsageValue());
    }

    public function testLabelPrefersComponentThenFallsBackToType(): void
    {
        $paper = new ServiceInterval('air_filter', MaintenanceAction::Replace, 'web', component: 'paper cartridge');
        $oil = new ServiceInterval('oil_change', MaintenanceAction::Change, 'web');

        $this->assertSame('paper cartridge', $paper->label());
        $this->assertSame('oil_change', $oil->label());
    }

    public function testDeleteTombstonesWithoutErasing(): void
    {
        $oil = new ServiceInterval('oil_change', MaintenanceAction::Change, 'web');

        $this->assertFalse($oil->isDeleted());
        $oil->delete(new \DateTimeImmutable('2026-06-22'));
        $this->assertTrue($oil->isDeleted());
    }
}
