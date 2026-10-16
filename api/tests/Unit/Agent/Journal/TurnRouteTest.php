<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Journal;

use App\Agent\Journal\TurnRoute;
use PHPUnit\Framework\TestCase;

final class TurnRouteTest extends TestCase
{
    public function testASearchWithAPlateAndACategory(): void
    {
        $route = TurnRoute::forSearch(['rule' => 'category', 'plate' => 'XB-301-ZB', 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'categoryId' => 100259]);

        $this->assertSame('p/XB-301-ZB/c/100259', $route->route);
        $this->assertSame(['plate' => 'XB-301-ZB', 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => null], $route->vehicle);
    }

    public function testAPlateLookupWithoutCategory(): void
    {
        $this->assertSame('p/XB301ZB', TurnRoute::forSearch(['rule' => 'plate', 'plate' => 'XB 301 ZB', 'carId' => 19107])->route);
        $this->assertSame('p/XB-301-ZB', TurnRoute::forSearch(['rule' => 'plate', 'plate' => 'XB-301-ZB'])->route);
    }

    public function testAPlatelessSearch(): void
    {
        $route = TurnRoute::forSearch(['rule' => 'category', 'carId' => 19107, 'carType' => 'PC', 'categoryId' => 100259]);

        $this->assertSame('k/19107-PC/c/100259', $route->route);
        $this->assertSame(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => null, 'model' => null, 'engine' => null], $route->vehicle);
    }

    public function testASearchWithNoVehicle(): void
    {
        $route = TurnRoute::forSearch(['rule' => 'vin', 'outcome' => 'ok']);

        $this->assertNull($route->route);
        $this->assertNull($route->vehicle);
    }

    public function testAnAgentTurnTakesTheLastSetVehicleAndTheLastShowParts(): void
    {
        $route = TurnRoute::forAgent(['carId' => 1, 'carType' => 'PC', 'plate' => null, 'make' => 'X', 'model' => 'Y'], [
            ['name' => 'set_vehicle', 'arguments' => ['carId' => 2, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '5']],
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100001]],
            ['name' => 'set_vehicle', 'arguments' => ['carId' => 9586, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'plate' => 'xb-301-zb']],
            ['name' => 'web_search', 'arguments' => ['query' => 'x']],
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100259, 'categoryLabel' => 'Rotule']],
        ]);

        $this->assertSame('p/XB-301-ZB/c/100259', $route->route);
        $this->assertSame(['plate' => 'XB-301-ZB', 'carId' => 9586, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'engine' => null], $route->vehicle);
    }

    public function testAnAgentTurnFallsBackToThePageVehicle(): void
    {
        $route = TurnRoute::forAgent(['carId' => 19107, 'carType' => 'PC', 'plate' => null, 'make' => 'MAZDA', 'model' => '6'], [
            ['name' => 'ask_question', 'arguments' => ['question' => 'Avant ?']],
        ]);

        $this->assertSame('k/19107-PC', $route->route);
        $this->assertSame(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => null], $route->vehicle);
    }

    public function testAnAgentTurnWithNoVehicleHasNoRoute(): void
    {
        $route = TurnRoute::forAgent(null, [['name' => 'show_parts', 'arguments' => ['categoryId' => 100259]]]);

        $this->assertNull($route->route);
        $this->assertNull($route->vehicle);
    }

    public function testASearchInAProductGroupCarriesIt(): void
    {
        $this->assertSame('p/XD-303-ZD/c/100198/g/51', TurnRoute::forSearch(['plate' => 'XD-303-ZD', 'carId' => 16182, 'carType' => 'PC', 'categoryId' => 100198, 'productGroupId' => 51])->route);
        $this->assertSame('k/16182-PC/c/100198/g/51', TurnRoute::forSearch(['carId' => 16182, 'carType' => 'PC', 'categoryId' => 100198, 'productGroupId' => 51])->route);
    }

    public function testAnAgentTurnTakesTheProductGroupTheShownResultNarrowed(): void
    {
        $route = TurnRoute::forAgent(['carId' => 16182, 'carType' => 'PC', 'plate' => 'XD-303-ZD'], [
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100198], 'result' => '{"status":"choose_group","productGroups":[]}'],
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100198, 'productGroupId' => 51], 'result' => '{"status":"shown","productGroup":{"id":51,"name":"Rotule de direction intérieure, barre de connexion"}}'],
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100777], 'result' => '{"status":"empty"}'],
        ]);

        $this->assertSame('p/XD-303-ZD/c/100198/g/51', $route->route);
    }

    public function testASingleGroupResultCarriesItsGroupEvenWhenTheAgentGaveNone(): void
    {
        $route = TurnRoute::forAgent(['carId' => 19107, 'carType' => 'PC'], [
            ['name' => 'show_parts', 'arguments' => ['categoryId' => 100259], 'result' => '{"status":"shown","productGroup":{"id":7,"name":"Filtre à huile"}}'],
        ]);

        $this->assertSame('k/19107-PC/c/100259/g/7', $route->route);
    }

    public function testThePlateIsUrlEncoded(): void
    {
        $this->assertSame('p/AB%2F12', TurnRoute::forSearch(['plate' => 'AB/12'])->route);
    }
}
