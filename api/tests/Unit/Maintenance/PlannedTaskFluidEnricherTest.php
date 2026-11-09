<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\VehicleFact;
use App\Maintenance\PlannedTaskFluidEnricher;
use PHPUnit\Framework\TestCase;

/**
 * Derives the fluid line ("what oil, what quantity") of a fluid-service task from the vehicle's
 * projected facts at read time — the part schema stays {ref,label,brand,qty}; the spec is a derived,
 * read-only sub-object. A ref-less fluid part of an oil_change picks up oil_viscosity/oil_type/
 * oil_capacity; a cataloged part (with a ref) and a non-fluid task are left untouched.
 */
final class PlannedTaskFluidEnricherTest extends TestCase
{
    private PlannedTaskFluidEnricher $enricher;

    protected function setUp(): void
    {
        $this->enricher = new PlannedTaskFluidEnricher();
    }

    public function testEnrichesRefLessOilPartFromFacts(): void
    {
        $parts = [
            ['id' => 'part-1', 'ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile'],
            ['id' => 'part-2', 'ref' => '', 'label' => 'Huile moteur', 'qty' => 5],
        ];

        $enriched = $this->enricher->enrich('oil_change', $parts, $this->oilFacts());

        // The cataloged filter (it has a ref) is left alone.
        $this->assertArrayNotHasKey('spec', $enriched[0]);

        // The ref-less engine-oil line picks up the derived spec.
        $this->assertSame([
            'verified' => true,
            'viscosity' => '5W-30',
            'standard' => 'ACEA A3/B3|ACEA A5/B5',
            'capacity' => '4.3',
            'unit' => 'L',
        ], $enriched[1]['spec'] ?? null);
        // The stored part fields survive untouched.
        $this->assertSame('Huile moteur', $enriched[1]['label'] ?? null);
        $this->assertSame(5, $enriched[1]['qty'] ?? null);
    }

    public function testEnrichesOnlyTheFirstFluidPart(): void
    {
        $parts = [
            ['id' => 'part-3', 'ref' => '', 'label' => 'Huile moteur'],
            ['id' => 'part-4', 'ref' => '', 'label' => 'Bidon supplémentaire'],
        ];

        $enriched = $this->enricher->enrich('oil_change', $parts, $this->oilFacts());

        $this->assertArrayHasKey('spec', $enriched[0]);
        $this->assertArrayNotHasKey('spec', $enriched[1]);
    }

    public function testLeavesNonFluidTaskUntouched(): void
    {
        $parts = [['id' => 'part-5', 'ref' => '', 'label' => 'Plaquettes']];

        $this->assertSame($parts, $this->enricher->enrich('brakes', $parts, $this->oilFacts()));
    }

    public function testDerivesFreeTextFluidTypeUsingTypeAsPrefix(): void
    {
        $parts = [['id' => 'part-6', 'ref' => '', 'label' => 'Huile de boîte']];
        $facts = [
            VehicleFact::fromSource('gearbox_oil_type', 'API GL4 SAE 75W-90', 'partsapi'),
            VehicleFact::fromSource('gearbox_oil_capacity', '2.95', 'partsapi', unit: 'liter'),
        ];

        $enriched = $this->enricher->enrich('gearbox_oil', $parts, $facts);

        $this->assertSame([
            'verified' => true,
            'standard' => 'API GL4 SAE 75W-90',
            'capacity' => '2.95',
            'unit' => 'liter',
        ], $enriched[0]['spec'] ?? null);
    }

    public function testNoMatchingFactsLeavesPartsUntouched(): void
    {
        $parts = [['id' => 'part-7', 'ref' => '', 'label' => 'Huile moteur']];

        $this->assertSame($parts, $this->enricher->enrich('oil_change', $parts, []));
    }

    public function testUnverifiedStandardYieldsUnverifiedSpec(): void
    {
        $parts = [['id' => 'part-8', 'ref' => '', 'label' => 'Huile moteur']];
        $facts = [VehicleFact::fromSource('oil_capacity', '4.3', 'user', unit: 'L')];

        $enriched = $this->enricher->enrich('oil_change', $parts, $facts);

        $this->assertFalse($enriched[0]['spec']['verified'] ?? null);
        $this->assertSame('4.3', $enriched[0]['spec']['capacity'] ?? null);
    }

    /**
     * @return list<VehicleFact>
     */
    private function oilFacts(): array
    {
        return [
            VehicleFact::fromSource('oil_capacity', '4.3', 'user', unit: 'L'),
            VehicleFact::fromSource('oil_type', 'ACEA A3/B3|ACEA A5/B5', 'partsapi'),
            VehicleFact::fromSource('oil_viscosity', '5W-30', 'web', url: 'https://oil-change.info'),
        ];
    }
}
