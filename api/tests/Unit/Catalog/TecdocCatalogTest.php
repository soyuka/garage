<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;
use App\Catalog\ArticleCriterion;
use App\Catalog\ArticleCross;
use App\Catalog\PartsApiCatalog;
use App\Catalog\PartsCatalog;
use App\Catalog\TecdocCatalog;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use App\Tests\Support\TecdocFixtureDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class TecdocCatalogTest extends TestCase
{
    private string $dir;
    private TecdocCatalog $catalog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tecdoc-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite');

        $this->catalog = new TecdocCatalog($this->dir.'/catalog.sqlite');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    #[DataProvider('unreadablePaths')]
    public function testAnUnreadableCatalogPathFailsClearly(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TECDOC_CATALOG_PATH');

        (new TecdocCatalog($path))->makes(CarType::PassengerCar);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'missing file' => ['/nonexistent/catalog.sqlite'];
    }

    public function testItDependsOnNoPartsCatalogAndNoPartsApiClient(): void
    {
        $forbidden = [PartsCatalog::class, PartsApiCatalog::class, PartsApiClient::class];

        foreach ((new ReflectionClass(TecdocCatalog::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertNotContains($type->getName(), $forbidden, '$'.$parameter->getName());
        }
    }

    public function testMakesAreThePassengerCarManufacturersByName(): void
    {
        $names = array_map(static fn (Make $make): string => $make->name, $this->catalog->makes(CarType::PassengerCar));

        $this->assertSame(['ABARTH', 'CITROËN', 'FORD', 'IVECO', 'JAGUAR', 'LAND ROVER', 'MAZDA', 'ROVER', 'SAAB', 'VOLVO'], $names);
    }

    public function testMakesAreFilteredBySearchLikePartsApi(): void
    {
        $this->assertEquals([new Make(72, 'MAZDA')], $this->catalog->makes(CarType::PassengerCar, 'azd'));
    }

    public function testModelsPreferTheFrenchNameAndCarryYearMonths(): void
    {
        $this->assertEquals(
            [
                new Model(82, 'XEDOS 6 (CA)', '1992-01', '1999-10'),
                new Model(4887, '6 Hatchback (GG)', '2002-08', '2008-12'),
                new Model(6987, '6 A trois volumes (GH)', '2007-08', '2013-12'),
            ],
            $this->catalog->models(72, CarType::PassengerCar),
        );
    }

    public function testCarsCarryTheFieldsTheConsumersRead(): void
    {
        $cars = $this->catalog->cars(72, 4887, CarType::PassengerCar);

        $this->assertSame([16680, 16681, 16682, 16683, 16844, 18827, 19107, 45440, 45441, 52834, 58123, 58124, 108677], array_map(static fn (Car $car): int => $car->id, $cars));
        $byId = array_column(array_map(static fn (Car $car): array => ['id' => $car->id, 'car' => $car], $cars), 'car', 'id');
        $this->assertEquals(
            new Car(19107, '2.0 (LFF7)', 'MAZDA 6 Hatchback (GG) 2.0 (LFF7)', '3/5 portes', 'Essence', 1999, 108, 147, '2005-03', '2007-08'),
            $byId[19107],
        );
        $this->assertEquals(
            new Car(16683, '2.0 DI (RF5C)', 'MAZDA 6 Hatchback (GG) 2.0 DI (RF5C)', '3/5 portes', 'Diesel', 1998, 100, 136, '2002-08', '2007-08'),
            $byId[16683],
        );
    }

    public function testCarsHaveTheSameFieldTypesAsThePartsApiPath(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/partsapi/getCars_72_4887_PC.json'), true, flags: \JSON_THROW_ON_ERROR);
        $partsApi = array_values(array_filter(
            array_map(Car::fromPartsApi(...), $rows),
            static fn (Car $car): bool => 19107 === $car->id,
        ))[0];
        $tecdoc = array_values(array_filter(
            $this->catalog->cars(72, 4887, CarType::PassengerCar),
            static fn (Car $car): bool => 19107 === $car->id,
        ))[0];

        $types = static fn (Car $car): array => array_map(get_debug_type(...), get_object_vars($car));
        $this->assertSame($types($partsApi), $types($tecdoc));
        $this->assertSame($partsApi->capacityCc, $tecdoc->capacityCc);
        $this->assertSame($partsApi->powerKw, $tecdoc->powerKw);
        $this->assertSame($partsApi->powerPs, $tecdoc->powerPs);
        $this->assertSame($partsApi->yearFrom, $tecdoc->yearFrom);
        $this->assertSame($partsApi->yearTo, $tecdoc->yearTo);
    }

    public function testPartCategoriesAreTheCarsLinkedNodesAndTheirAncestors(): void
    {
        $this->assertEquals(
            [
                new PartCategory(100005, null, 1, 'Filtre', 'Filtre', false),
                new PartCategory(100012, null, 1, 'Direction', 'Direction', false),
                new PartCategory(100259, 100005, 2, 'Filtre à huile', 'Filtre > Filtre à huile', true),
                new PartCategory(100198, 100012, 2, 'Rotules', 'Direction > Rotules', true),
            ],
            $this->catalog->partCategories(19107, CarType::PassengerCar, false),
        );
    }

    public function testPartCategoriesLeafOnlyAndSearchBehaveLikePartsApi(): void
    {
        $leaves = $this->catalog->partCategories(19107, CarType::PassengerCar);
        $this->assertSame([100259, 100198], array_map(static fn (PartCategory $c): int => $c->id, $leaves));

        $found = $this->catalog->partCategories(19107, CarType::PassengerCar, true, 'huile');
        $this->assertSame([100259], array_map(static fn (PartCategory $c): int => $c->id, $found));
    }

    public function testPartCategoriesOfACarWithoutLinksAreEmpty(): void
    {
        $this->assertSame([], $this->catalog->partCategories(16680, CarType::PassengerCar, false));
    }

    public function testCachedCategoryNameIsTheFrenchLeafOfTheCarsTree(): void
    {
        $this->assertSame('Filtre à huile', $this->catalog->cachedCategoryName(19107, 100259, CarType::PassengerCar));
        $this->assertNull($this->catalog->cachedCategoryName(19107, 100017, CarType::PassengerCar));
    }

    public function testCommercialVehicleMakesAreTheOnesWithCommercialTypes(): void
    {
        $this->assertEquals(
            [new Make(36, 'FORD'), new Make(55, 'IVECO'), new Make(120, 'VOLVO')],
            $this->catalog->makes(CarType::CommercialVehicle),
        );
        $this->assertEquals([new Make(55, 'IVECO')], $this->catalog->makes(CarType::CommercialVehicle, 'ivec'));
    }

    public function testCommercialVehicleModelsAreTheCommercialOnes(): void
    {
        $this->assertEquals(
            [new Model(3421, 'EuroCargo I-III', '1991-01', '2015-09')],
            $this->catalog->models(55, CarType::CommercialVehicle),
        );
    }

    public function testCommercialVehicleCarsComeFromCvTypeWithTheirEngineCodes(): void
    {
        $cars = $this->catalog->cars(55, 3421, CarType::CommercialVehicle);

        $this->assertSame([1446, 1451], array_map(static fn (Car $car): int => $car->id, $cars));
        $this->assertEquals(
            new Car(1446, '60 E 10 (LFF7)', 'IVECO EuroCargo I-III 60 E 10 (LFF7)', null, null, 3908, 75, 102, '1991-01', '2015-09'),
            $cars[0],
        );
        $this->assertSame('60 E 12', $cars[1]->name);
    }

    public function testCommercialVehicleCategoriesComeFromTreeTwoThroughCvTypeGenart(): void
    {
        $this->assertEquals(
            [
                new PartCategory(200026, null, 1, 'Moteur', 'Moteur', false),
                new PartCategory(200033, 200026, 2, 'Lubrification', 'Moteur > Lubrification', false),
                new PartCategory(200136, 200033, 3, 'Filtre à huile', 'Moteur > Lubrification > Filtre à huile', true),
            ],
            $this->catalog->partCategories(1446, CarType::CommercialVehicle, false),
        );
        $this->assertSame('Filtre à huile', $this->catalog->cachedCategoryName(1446, 200136, CarType::CommercialVehicle));
    }

    public function testACommercialCarIdIsOnlyLookedUpAmongCommercialTypes(): void
    {
        $this->assertSame([], $this->catalog->partCategories(19107, CarType::CommercialVehicle, false));
        $this->assertSame([], $this->catalog->articles(19107, 100259, CarType::CommercialVehicle));
    }

    public function testArticlesOfACarAndACategoryCarryTheConsumedFields(): void
    {
        $articles = $this->catalog->articles(19107, 100259, CarType::PassengerCar);

        $this->assertEquals(
            [
                new Article(407986, '0 451 103 363', 'BOSCH', 'Filtre à huile', 7, 30),
                new Article(1014343, 'PH9566', 'FRAM', 'Filtre à huile', 7, 59),
                new Article(3160312, 'OC 1182', 'MAHLE', 'Filtre à huile', 7, 287),
                new Article(78351, 'W 712/73', 'MANN-FILTER', 'Filtre à huile', 7, 4),
                new Article(5785770, 'JAPFO-394S', 'PEMEBLA', 'Filtre à huile', 7, 0),
                new Article(786358, 'LS357', 'PURFLUX', 'Filtre à huile', 7, 38),
                new Article(259479, '586083', 'VALEO', 'Filtre à huile', 7, 21),
            ],
            $articles,
        );
    }

    public function testArticlesOfACategoryTheCarDoesNotLinkAreEmpty(): void
    {
        $this->assertSame([], $this->catalog->articles(19107, 100005, CarType::PassengerCar));
        $this->assertSame([], $this->catalog->articles(16680, 100259, CarType::PassengerCar));
    }

    public function testCommercialVehicleArticlesComeFromArticleCvType(): void
    {
        $articles = $this->catalog->articles(1446, 200136, CarType::CommercialVehicle);

        $this->assertSame([78351], array_map(static fn (Article $article): int => $article->id, $articles));
        $this->assertSame('MANN-FILTER', $articles[0]->brand);
    }

    public function testCachedArticlesAreTheSameLocalData(): void
    {
        $this->assertEquals(
            $this->catalog->articles(19107, 100259, CarType::PassengerCar),
            $this->catalog->cachedArticles(19107, 100259, CarType::PassengerCar),
        );
    }

    public function testArticleCriteriaAreTheAttributesWithTheirCriterionIdAndUnit(): void
    {
        $this->assertEquals(
            [
                new ArticleCriterion("Référence de l'outil spécialisé recommandé", 'LS 7', 1361),
                new ArticleCriterion('Hauteur [mm]', '93', 209),
                new ArticleCriterion('Filetage', '3/4-16 UNF', 289),
                new ArticleCriterion('Diamètre extérieur [mm]', '76', 497),
                new ArticleCriterion('Diamètre extérieur 2 [mm]', '71', 979),
                new ArticleCriterion('Diamètre intérieur 2 [mm]', '62', 996),
                new ArticleCriterion("Pression d'ouverture clapet de dérivation [bar]", '1', 1069),
            ],
            $this->catalog->articleCriteria(78351),
        );
    }

    public function testFractionalMeasuresKeepAFrenchDecimalComma(): void
    {
        $values = array_column(
            array_map(static fn (ArticleCriterion $c): array => ['name' => $c->name, 'value' => $c->value], $this->catalog->articleCriteria(407986)),
            'value',
            'name',
        );

        $this->assertSame('93,5', $values['Hauteur [mm]']);
    }

    public function testArticleCriteriaAddTheUnionOfTheLinkQualifiersJoinedLikePartsApi(): void
    {
        $criteria = $this->catalog->articleCriteria(407986);

        $this->assertContainsEquals(new ArticleCriterion('Nº de châssis à partir de', '176451', 25), $criteria);
        $this->assertContainsEquals(new ArticleCriterion('Code moteur', 'LF', 33), $criteria);
        $this->assertContainsEquals(new ArticleCriterion('Année à partir de', '200504', 20), $this->catalog->articleCriteria(259479));
    }

    public function testCachedArticleCriteriaBatchesByArticleId(): void
    {
        $criteria = $this->catalog->cachedArticleCriteria([78351, 407986]);

        $this->assertSame([78351, 407986], array_keys($criteria));
        $this->assertEquals($this->catalog->articleCriteria(78351), $criteria[78351]);
        $this->assertEquals($this->catalog->articleCriteria(407986), $criteria[407986]);
        $this->assertSame([], $this->catalog->cachedArticleCriteria([]));
    }

    public function testAnUnknownArticleHasNoCriteriaNorCrosses(): void
    {
        $this->assertSame([], $this->catalog->articleCriteria(1));
        $this->assertSame([], $this->catalog->articleCrosses(1));
    }

    public function testArticleCrossesAreTheOeAndComparisonNumbersWithResolvedArticleIds(): void
    {
        $crosses = $this->catalog->articleCrosses(78351);

        $this->assertCount(54, $crosses);
        $this->assertEquals(new ArticleCross('MAZDA', 'LF05-14-302A', null, ''), $crosses[0]);
        $this->assertContainsEquals(new ArticleCross('VALEO', '586083', 259479, 'Filtre à huile'), $crosses);
        $this->assertContainsEquals(new ArticleCross('BOSCH', '0 451 103 363', 407986, 'Filtre à huile'), $crosses);
        $this->assertContainsEquals(new ArticleCross('PURFLUX', 'LS357', 786358, 'Filtre à huile'), $crosses);
        $this->assertContainsEquals(new ArticleCross('MAHLE FILTER', 'OC 466', null, ''), $crosses);
    }

    public function testArticleCrossesEndWithTheArticlesCitingTheSameOeNumbers(): void
    {
        $crosses = $this->catalog->articleCrosses(78351);

        $this->assertEquals(new ArticleCross('MAHLE', 'OC 1182', 3160312, 'Filtre à huile'), $crosses[52]);
        $this->assertEquals(new ArticleCross('PEMEBLA', 'JAPFO-394S', 5785770, 'Filtre à huile'), $crosses[53]);
        $this->assertCount(1, array_filter($crosses, static fn (ArticleCross $cross): bool => 78351 === $cross->artId));
    }

    public function testAnOeListedUnderTheLongManufacturerNameResolvesThroughTheSupplierShortName(): void
    {
        $pdo = new PDO('sqlite:'.$this->dir.'/catalog.sqlite');
        $pdo->exec("INSERT INTO manufacturer VALUES (99990, 99990, 'MAHLE', 'MAHLE FILTER', 0, 0, 0, 0, 1, 0, 0)");
        $pdo->exec("INSERT INTO article_oe_number VALUES (78351, 9999, 99990, 'OC 1182', 0, NULL, NULL)");
        $pdo = null;

        $crosses = (new TecdocCatalog($this->dir.'/catalog.sqlite'))->articleCrosses(78351);

        $this->assertContainsEquals(new ArticleCross('MAHLE FILTER', 'OC 1182', 3160312, 'Filtre à huile'), $crosses);
    }

    public function testNothingIsEverBoughtUpstream(): void
    {
        $this->catalog->articles(19107, 100259, CarType::PassengerCar);
        $this->catalog->articleCriteria(78351);

        $this->assertSame(0, $this->catalog->upstreamCalls());
        $this->assertSame([], $this->catalog->upstreamCallsByMethod());
    }
}
