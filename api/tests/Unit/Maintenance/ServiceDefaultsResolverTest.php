<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use App\Garage\VehicleType;
use App\Maintenance\Defaults\ServiceDefaultsCatalog;
use App\Maintenance\Defaults\ServiceDefaultsResolver;
use App\Maintenance\Defaults\ServiceDefaultsStatus;
use App\Maintenance\MaintenanceTypeNormalizer;
use App\Maintenance\UsageUnit;
use App\Maintenance\VehicleFactsProjector;
use PHPUnit\Framework\TestCase;

final class ServiceDefaultsResolverTest extends TestCase
{
    public function testAServiceWithNoDiscriminantResolvesWithoutAVehicle(): void
    {
        $resolution = $this->resolver()->resolve('brake_fluid');

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertNull($resolution->discriminantKey);
        $this->assertSame(24, $resolution->baseline()->everyMonths);
        $this->assertNull($resolution->baseline()->everyUsageValue);
    }

    public function testAServiceOutsideTheCatalogueIsUnknown(): void
    {
        $resolution = $this->resolver()->resolve('gearbox_oil');

        $this->assertSame(ServiceDefaultsStatus::Unknown, $resolution->status);
        $this->assertSame([], $resolution->candidates);
        $this->assertNull($resolution->baseline());
    }

    public function testTheDistributionIsAmbiguousUntilItsTypeIsKnown(): void
    {
        $resolution = $this->resolver()->resolve('timing_belt');

        $this->assertSame(ServiceDefaultsStatus::Ambiguous, $resolution->status);
        $this->assertSame('distribution_type', $resolution->discriminantKey);
        $this->assertCount(2, $resolution->candidates);
        // The whole point: no single number is handed back while the belt/chain question is open.
        $this->assertNull($resolution->baseline());
    }

    public function testASynonymIsCanonicalisedBeforeTheLookup(): void
    {
        $resolution = $this->resolver()->resolve('Courroie de distribution');

        $this->assertSame(ServiceDefaultsStatus::Ambiguous, $resolution->status);
        $this->assertSame('timing_belt', $resolution->type);
        $this->assertSame('distribution_type', $resolution->discriminantKey);
    }

    public function testAChainVehicleResolvesToNoIntervalAtAll(): void
    {
        $vehicle = $this->car();
        $this->fact($vehicle, 'distribution_type', 'chain');

        $resolution = $this->resolver()->resolve('timing_belt', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertSame('chain', $resolution->discriminantValue);
        $this->assertFalse($resolution->baseline()->hasCadence());
        $this->assertNull($resolution->baseline()->everyUsageValue);
        $this->assertNull($resolution->baseline()->everyMonths);
    }

    public function testABeltVehicleResolvesToTheBeltCadence(): void
    {
        $vehicle = $this->car();
        // A real fact value is rarely the bare token — "timing chain"/"courroie" style wording must
        // still settle the discriminant, otherwise the resolver stays ambiguous on known vehicles.
        $this->fact($vehicle, 'distribution_type', 'timing belt');

        $resolution = $this->resolver()->resolve('timing_belt', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertSame(120000, $resolution->baseline()->everyUsageValue);
        $this->assertSame(UsageUnit::Kilometers, $resolution->baseline()->everyUsageUnit);
        $this->assertSame(60, $resolution->baseline()->everyMonths);
    }

    public function testAnUnrecognisedDiscriminantValueStaysAmbiguous(): void
    {
        $vehicle = $this->car();
        $this->fact($vehicle, 'distribution_type', 'gear driven');

        $resolution = $this->resolver()->resolve('timing_belt', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Ambiguous, $resolution->status);
        $this->assertSame('distribution_type', $resolution->discriminantKey);
        $this->assertNull($resolution->baseline());
    }

    public function testSparkPlugsAreAmbiguousOnACarUntilTheElectrodeMaterialIsKnown(): void
    {
        $vehicle = $this->car();

        $resolution = $this->resolver()->resolve('spark_plug', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Ambiguous, $resolution->status);
        $this->assertSame('spark_plug_material', $resolution->discriminantKey);
    }

    public function testAnIridiumCarResolvesToTheLongLifeCadenceWithNoMonthAxis(): void
    {
        $vehicle = $this->car();
        $this->fact($vehicle, 'spark_plug_material', 'iridium');

        $resolution = $this->resolver()->resolve('spark_plug', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertSame(100000, $resolution->baseline()->everyUsageValue);
        // Plugs wear by combustion cycles, not by the calendar — no month axis is encoded.
        $this->assertNull($resolution->baseline()->everyMonths);
    }

    public function testTheGroundscareSegmentScopesSparkPlugsToTheEngineHourAxis(): void
    {
        $resolution = $this->resolver()->resolve('spark_plug', $this->mower());

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertSame(UsageUnit::Hours, $resolution->baseline()->everyUsageUnit);
        $this->assertNull($resolution->discriminantKey);
    }

    public function testOilChangeIsAmbiguousWithoutAVehicleBecauseTheSegmentsDiffer(): void
    {
        $resolution = $this->resolver()->resolve('oil_change');

        $this->assertSame(ServiceDefaultsStatus::Ambiguous, $resolution->status);
        // The split here is the vehicle segment, not a fact — no fact key can settle it.
        $this->assertNull($resolution->discriminantKey);
        $this->assertCount(2, $resolution->candidates);
    }

    public function testOilChangeResolvesPerSegment(): void
    {
        $mower = $this->resolver()->resolve('oil_change', $this->mower());
        $car = $this->resolver()->resolve('oil_change', $this->car());

        $this->assertSame(50, $mower->baseline()->everyUsageValue);
        $this->assertSame(UsageUnit::Hours, $mower->baseline()->everyUsageUnit);
        $this->assertSame(15000, $car->baseline()->everyUsageValue);
        $this->assertSame(UsageUnit::Kilometers, $car->baseline()->everyUsageUnit);
    }

    public function testAPetrolFuelFilterResolvesToNoScheduledInterval(): void
    {
        $vehicle = $this->car();
        $this->fact($vehicle, 'fuel_type', 'essence');

        $resolution = $this->resolver()->resolve('fuel_filter', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertFalse($resolution->baseline()->hasCadence());
    }

    public function testADieselFuelFilterResolvesToAUsageCadence(): void
    {
        $vehicle = $this->car();
        $this->fact($vehicle, 'fuel_type', 'diesel');

        $resolution = $this->resolver()->resolve('fuel_filter', $vehicle);

        $this->assertSame(ServiceDefaultsStatus::Resolved, $resolution->status);
        $this->assertSame(40000, $resolution->baseline()->everyUsageValue);
    }

    public function testEveryBaselineCarriesItsProvenanceAndReviewDate(): void
    {
        foreach ((new ServiceDefaultsCatalog())->all() as $baseline) {
            $this->assertNotSame('', $baseline->source, $baseline->key().' has no source');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $baseline->reviewedOn, $baseline->key().' has no review date');
            $this->assertNotSame([], $baseline->segments, $baseline->key().' is scoped to no segment');
        }
    }

    private function resolver(): ServiceDefaultsResolver
    {
        return new ServiceDefaultsResolver(
            new ServiceDefaultsCatalog(),
            new VehicleFactsProjector(),
            new MaintenanceTypeNormalizer(),
        );
    }

    private function car(): Vehicle
    {
        return new Vehicle('MAZDA', 'MAZDA6', VehicleType::Car, 'llm');
    }

    private function mower(): Vehicle
    {
        return new Vehicle('TORO', '246-H', VehicleType::GroundsCare, 'llm');
    }

    /**
     * Facts live on a maintenance item's attributes and are read back through the projection —
     * the same seam get_vehicle_facts uses. There is no separate fact store to seed.
     */
    private function fact(Vehicle $vehicle, string $key, string $value): void
    {
        $task = new PlannedMaintenance($vehicle, 'inspection', 'llm');
        $task->setAttribute(new VehicleFact($key, $value, 'llm', false));
    }
}
