<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\MaintenanceRecord;
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

    public function testALoggedPartIsProjectedAsARefFact(): void
    {
        $vehicle = $this->vehicle();
        // A logged visit that fitted a real oil filter — the part is evidence of the vehicle's spec.
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('oil_filter_ref', $facts[0]->getKey());
        $this->assertSame('MANN-FILTER W712/73', $facts[0]->getValue());
        $this->assertSame('log', $facts[0]->getSource());
    }

    public function testARefFactKeepsTheBarePartNumberWhenNoBrand(): void
    {
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'air_filter', 'parts' => [['ref' => 'C25024']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertSame('air_filter_ref', $facts[0]->getKey());
        $this->assertSame('C25024', $facts[0]->getValue());
    }

    public function testAnItemWithNoPartRefProducesNoFact(): void
    {
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_change', 'parts' => []]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );

        $this->assertSame([], $this->projector()->forVehicle($vehicle));
    }

    public function testOtherTypeItemsDoNotProduceRefFactsToAvoidCollapsing(): void
    {
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'other', 'label' => 'blade sharpening', 'parts' => [['ref' => 'XYZ']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );

        $this->assertSame([], $this->projector()->forVehicle($vehicle));
    }

    public function testALoggedBillPartOutranksAVerifiedCatalogSpecForTheSameKey(): void
    {
        $vehicle = $this->vehicle();
        // The catalog guessed an oil filter ref; the user's bill shows the part actually fitted.
        // A real installed part is ground truth — it must outrank even a verified catalog spec.
        $catalog = new PlannedMaintenance($vehicle, 'catalog_specs', 'partsapi');
        $catalog->setAttribute(new VehicleFact('oil_filter_ref', 'WRONG-123', 'partsapi', true, addedAt: new \DateTimeImmutable('2026-06-18')));
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73', 'brand' => 'MANN-FILTER']]]],
            new \DateTimeImmutable('2026-01-01'), // older than the catalog spec, yet must still win
            'user',
        );

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('MANN-FILTER W712/73', $facts[0]->getValue());
        $this->assertSame('log', $facts[0]->getSource());
    }

    public function testAmongLoggedPartsTheMostRecentVisitWins(): void
    {
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'OLD-REF']]]],
            new \DateTimeImmutable('2025-01-01'),
            'user',
        );
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'NEW-REF']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('NEW-REF', $facts[0]->getValue());
    }

    public function testAManualUserFactOutranksBothALoggedPartAndAVerifiedSpec(): void
    {
        $vehicle = $this->vehicle();
        // The owner's deliberate dashboard correction is ground truth: it must beat a verified catalog
        // spec AND a logged part for the same key, even when it is the oldest of the three.
        $catalog = new PlannedMaintenance($vehicle, 'catalog_specs', 'partsapi');
        $catalog->setAttribute(new VehicleFact('oil_filter_ref', 'CATALOG-REF', 'partsapi', true, addedAt: new \DateTimeImmutable('2026-06-18')));
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'PART-REF']]]],
            new \DateTimeImmutable('2026-06-19'),
            'user',
        );
        $userFacts = new PlannedMaintenance($vehicle, 'user_facts', 'user');
        $userFacts->setAttribute(VehicleFact::fromSource('oil_filter_ref', 'USER-OVERRIDE', 'user', addedAt: new \DateTimeImmutable('2026-06-01')));

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('USER-OVERRIDE', $facts[0]->getValue());
        $this->assertSame('user', $facts[0]->getSource());
    }

    public function testASuppressedKeyHidesTheLoggedPartFact(): void
    {
        $vehicle = $this->vehicle();
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'W712/73']]]],
            new \DateTimeImmutable('2026-06-15'),
            'user',
        );
        // The user removed it from the card after the visit — suppressed.
        $vehicle->suppressFactKey('oil_filter_ref', new \DateTimeImmutable('2026-06-20'));

        $this->assertSame([], $this->projector()->forVehicle($vehicle));
    }

    public function testALoggedPartNewerThanTheSuppressionResurfaces(): void
    {
        $vehicle = $this->vehicle();
        // Hidden on the 20th; a fresh fitment on July 1 is new evidence — it must come back.
        $vehicle->suppressFactKey('oil_filter_ref', new \DateTimeImmutable('2026-06-20'));
        new MaintenanceRecord(
            $vehicle,
            [['type' => 'oil_filter', 'parts' => [['ref' => 'NEW-REF']]]],
            new \DateTimeImmutable('2026-07-01'),
            'user',
        );

        $facts = $this->projector()->forVehicle($vehicle);

        $this->assertCount(1, $facts);
        $this->assertSame('NEW-REF', $facts[0]->getValue());
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
