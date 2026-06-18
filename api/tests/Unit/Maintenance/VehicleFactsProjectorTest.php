<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Garage\VehicleType;
use App\Maintenance\VehicleFactsProjector;
use PHPUnit\Framework\TestCase;

final class VehicleFactsProjectorTest extends TestCase
{
    public function testAVehicleWithNoItemsHasNoFacts(): void
    {
        $facts = $this->projector()->forVehicle($this->vehicle());

        $this->assertSame([], $facts);
    }

    public function testEveryItemAttributeIsProjected(): void
    {
        $vehicle = $this->vehicle();
        $oil = new PlannedMaintenance($vehicle, 'oil_change', 'web', targetUsageValue: 250000);
        $oil->setAttribute(new VehicleFact('oil_type', '5W30', 'web', false));
        $oil->setAttribute(new VehicleFact('oil_capacity', '4.3', 'web', false, unit: 'liter'));
        $coolant = new PlannedMaintenance($vehicle, 'coolant', 'web', targetUsageValue: 250000);
        $coolant->setAttribute(new VehicleFact('coolant_type', 'FL-22', 'web', false));

        $keys = array_map(static fn (VehicleFact $f): string => $f->getKey(), $this->projector()->forVehicle($vehicle));
        sort($keys);

        $this->assertSame(['coolant_type', 'oil_capacity', 'oil_type'], $keys);
    }

    public function testKnownSpecCarrierWithoutATargetStillFeedsTheProjection(): void
    {
        $vehicle = $this->vehicle();
        // A catalog spec sheet: no target, off the checklist, but its specs are facts.
        $specs = new PlannedMaintenance($vehicle, 'catalog_specs', 'partsapi');
        $specs->setAttribute(new VehicleFact('oil_capacity', '4.3', 'partsapi', true, unit: 'liter'));

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('oil_capacity', $facts[0]->getKey());
        $this->assertTrue($facts[0]->isVerified());
    }

    public function testAVerifiedAttributeWinsOverAnUnverifiedOneForTheSameKey(): void
    {
        $vehicle = $this->vehicle();
        // Agent wrote it yesterday (web, unverified); catalog confirmed it today (verified). Despite
        // the agent's being older, the verified value must win — the precedence rule that stops a
        // hallucinated spec shadowing an authoritative one.
        $agent = new PlannedMaintenance($vehicle, 'oil_change', 'web', targetUsageValue: 250000);
        $agent->setAttribute(new VehicleFact('oil_capacity', '5.0', 'web', false, addedAt: new \DateTimeImmutable('2026-06-01')));
        $catalog = new PlannedMaintenance($vehicle, 'catalog_specs', 'partsapi');
        $catalog->setAttribute(new VehicleFact('oil_capacity', '4.3', 'partsapi', true, addedAt: new \DateTimeImmutable('2026-06-18')));

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('4.3', $facts[0]->getValue());
        $this->assertSame('partsapi', $facts[0]->getSource());
    }

    public function testAmongEquallyVerifiedAttributesTheNewestWins(): void
    {
        $vehicle = $this->vehicle();
        $old = new PlannedMaintenance($vehicle, 'oil_change', 'web', targetUsageValue: 100000);
        $old->setAttribute(new VehicleFact('oil_type', '5W30', 'web', false, addedAt: new \DateTimeImmutable('2026-01-01')));
        $new = new PlannedMaintenance($vehicle, 'oil_change', 'web', targetUsageValue: 250000, label: 'rev');
        $new->setAttribute(new VehicleFact('oil_type', '0W20', 'web', false, addedAt: new \DateTimeImmutable('2026-06-01')));

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('0W20', $facts[0]->getValue());
    }

    private function projector(): VehicleFactsProjector
    {
        return new VehicleFactsProjector();
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'llm');
    }
}
