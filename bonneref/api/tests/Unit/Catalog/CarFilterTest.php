<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\ApiResource\Car;
use App\Catalog\CarFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CarFilterTest extends TestCase
{
    private function car(int $id, string $engineType, int $cc, string $from, ?string $to, string $name = 'x'): Car
    {
        return new Car($id, $name, 'MAZDA '.$name, null, $engineType, $cc, null, null, $from, $to);
    }

    public function testOpenEndedVersionIsStillProduced(): void
    {
        $cars = [$this->car(1, 'Diesel', 1998, '2015-03', null), $this->car(2, 'Diesel', 1998, '2002-01', '2007-08')];

        $this->assertSame([1], array_map(static fn (Car $c): int => $c->id, CarFilter::apply($cars, year: 2024)));
    }

    public function testYearBoundsAreInclusive(): void
    {
        $cars = [$this->car(1, 'Diesel', 1998, '2002-08', '2007-08')];

        $this->assertCount(1, CarFilter::apply($cars, year: 2002));
        $this->assertCount(1, CarFilter::apply($cars, year: 2007));
        $this->assertCount(0, CarFilter::apply($cars, year: 2008));
        $this->assertCount(0, CarFilter::apply($cars, year: 2001));
    }

    public function testCapacityMatchesTheLitrePartWithinTolerance(): void
    {
        $cars = [$this->car(1, 'Diesel', 1999, '2002-01', null), $this->car(2, 'Diesel', 1798, '2002-01', null), $this->car(3, 'Diesel', 2260, '2002-01', null)];

        $this->assertSame([1], array_map(static fn (Car $c): int => $c->id, CarFilter::apply($cars, capacityLitres: 2.0)));
        $this->assertSame([3], array_map(static fn (Car $c): int => $c->id, CarFilter::apply($cars, capacityLitres: 2.3)));
    }

    #[DataProvider('fuels')]
    public function testFuelMapsFromEngineType(string $engineType, string $fuel, bool $expected): void
    {
        $this->assertSame($expected ? 1 : 0, \count(CarFilter::apply([$this->car(1, $engineType, 1998, '2002-01', null)], fuel: $fuel)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function fuels(): iterable
    {
        yield 'petrol is essence' => ['Petrol Engine', 'essence', true];
        yield 'petrol is not diesel' => ['Petrol Engine', 'diesel', false];
        yield 'diesel' => ['Diesel', 'diesel', true];
        yield 'hybrid is not essence' => ['Petrol/Electric Hybrid', 'essence', false];
        yield 'hybrid' => ['Petrol/Electric Hybrid', 'hybride', true];
        yield 'electric' => ['Electric Engine', 'électrique', true];
        yield 'electric unaccented' => ['Electric Engine', 'electrique', true];
        yield 'lpg' => ['Petrol Engine / LPG', 'GPL', true];
    }

    public function testSearchIsCaseInsensitiveOnNameAndFullName(): void
    {
        $cars = [$this->car(1, 'Diesel', 1998, '2002-01', null, '2.0 DI (GG14)'), $this->car(2, 'Diesel', 1998, '2002-01', null, '1.8')];

        $this->assertSame([1], array_map(static fn (Car $c): int => $c->id, CarFilter::apply($cars, search: 'gg14')));
        $this->assertSame([1, 2], array_map(static fn (Car $c): int => $c->id, CarFilter::apply($cars, search: 'mazda')));
    }
}
