<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Finder;

use App\Agent\Finder\FinderCategoryList;
use App\ApiResource\PartCategory;
use App\Catalog\CarType;
use App\Catalog\PartsCatalog;
use App\Catalog\TecdocCatalog;
use App\Exception\UpstreamException;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;

final class FinderCategoryListTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/finder-categories-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testEveryLeafOfTheCarTreeIsListedUnderItsBreadcrumb(): void
    {
        $lines = explode("\n", $this->list()->forVehicle(19107, CarType::PassengerCar));

        $this->assertSame(['[Direction]', '100198 Rotules', '[Filtre]', '100259 Filtre à huile'], $lines);
    }

    public function testNonLeafNodesToolsAndDiagramEntriesAreLeftOut(): void
    {
        $leaf = static fn (int $id, string $path): PartCategory => new PartCategory($id, null, 2, trim(substr(strrchr(' > '.$path, '>'), 1)), $path, true);

        $list = FinderCategoryList::format([
            $leaf(100198, 'Direction > Rotules'),
            $leaf(706383, 'Outils spéciaux > Direction > Rotules'),
            $leaf(103158, 'Carrosserie > Outils'),
            $leaf(706307, "Carrosserie > Recherche d'un article par graphique"),
            $leaf(101835, 'Carrosserie > Graphique complet des pièces de carrosserie'),
        ]);

        $this->assertSame("[Direction]\n100198 Rotules", $list);
    }

    public function testAnUnreachableCatalogueSaysSoInsteadOfThrowing(): void
    {
        $catalog = $this->createStub(PartsCatalog::class);
        $catalog->method('partCategories')->willThrowException(new UpstreamException('down'));

        $this->assertStringContainsString('indisponible', (new FinderCategoryList($catalog))->forVehicle(19107, CarType::PassengerCar));
    }

    private function list(): FinderCategoryList
    {
        return new FinderCategoryList(new TecdocCatalog($this->dir.'/catalog.sqlite'));
    }
}
