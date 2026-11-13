<?php

declare(strict_types=1);

namespace App\Tests\Unit\Garage;

use App\Catalog\CarType;
use App\Garage\VehicleType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleTypeTest extends TestCase
{
    #[DataProvider('catalogueTypes')]
    public function testEachSegmentMapsToItsTecDocCarType(VehicleType $type, ?CarType $expected): void
    {
        $this->assertSame($expected, $type->carType());
    }

    /**
     * @return iterable<string, array{VehicleType, CarType|null}>
     */
    public static function catalogueTypes(): iterable
    {
        yield 'car' => [VehicleType::Car, CarType::PassengerCar];
        yield 'moto' => [VehicleType::Moto, CarType::Motorbike];
        yield 'cv' => [VehicleType::CommercialVehicle, CarType::CommercialVehicle];
        yield 'groundscare has no TecDoc coverage' => [VehicleType::GroundsCare, null];
    }

    public function testTheWireValuesListEveryCarType(): void
    {
        $this->assertSame(['PC', 'CV', 'MC'], CarType::VALUES);
    }
}
