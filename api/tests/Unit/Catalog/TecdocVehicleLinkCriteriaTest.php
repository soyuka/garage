<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\ArticleCriterion;
use App\Catalog\TecdocCatalog;
use App\PartsApi\CarType;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;

final class TecdocVehicleLinkCriteriaTest extends TestCase
{
    private const int ESPACE = 18679;
    private const int ESPACE_TURBO = 16869;
    private const int FLYWHEEL = 577;
    private const int LUK = 95481;
    private const int SACHS_DATED = 635192;
    private const int KAWE = 3097422;

    private string $dir;
    private TecdocCatalog $catalog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tecdoc-link-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite', withEspaceFlywheels: true);

        $this->catalog = new TecdocCatalog($this->dir.'/catalog.sqlite');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheQualifiersAreThoseOfTheLinkToTheAskedCarOnly(): void
    {
        $links = $this->catalog->vehicleLinkCriteria(self::ESPACE, CarType::PassengerCar, [self::LUK, self::SACHS_DATED, self::KAWE], self::FLYWHEEL);

        $this->assertEquals(
            [[new ArticleCriterion('uniquement en connexion avec', '623 3150 ##', 427)]],
            $links[self::LUK]->qualifierSets,
        );
        $this->assertEquals(
            [[
                new ArticleCriterion("Année jusqu'à", '200602', 21),
                new ArticleCriterion('uniquement en connexion avec', '3000 951 220', 427),
                new ArticleCriterion("Type d'entraînement", 'Boîte de vitesses manuelle à 6 vitesses', 40),
            ]],
            $links[self::SACHS_DATED]->qualifierSets,
        );
        $this->assertEquals(
            [[
                new ArticleCriterion('Code moteur', 'F4R 797/897', 33),
                new ArticleCriterion('uniquement en connexion avec', '962318', 427),
            ]],
            $links[self::KAWE]->qualifierSets,
        );
    }

    public function testAnotherCarOfTheSameArticleGetsItsOwnQualifiers(): void
    {
        $links = $this->catalog->vehicleLinkCriteria(self::ESPACE_TURBO, CarType::PassengerCar, [self::LUK], self::FLYWHEEL);

        $this->assertEquals([[new ArticleCriterion('Année à partir de', '200603', 20)]], $links[self::LUK]->qualifierSets ?? null);
    }

    public function testTheUnionWithoutACarStillMergesEveryVehicle(): void
    {
        $this->assertContainsEquals(new ArticleCriterion('Année à partir de', '200603', 20), $this->catalog->articleCriteria(self::LUK));
    }

    public function testTheFingerprintCriteriaAreTheAttributesThenThisLinksQualifiers(): void
    {
        $criteria = $this->catalog->vehicleLinkCriteria(self::ESPACE, CarType::PassengerCar, [self::SACHS_DATED], self::FLYWHEEL)[self::SACHS_DATED]->criteria();

        $this->assertEquals(
            [
                new ArticleCriterion('Diamètre [mm]', '230', 200),
                new ArticleCriterion("Année jusqu'à", '200602', 21),
                new ArticleCriterion('uniquement en connexion avec', '3000 951 220', 427),
                new ArticleCriterion("Type d'entraînement", 'Boîte de vitesses manuelle à 6 vitesses', 40),
            ],
            $criteria,
        );
    }

    public function testEveryAskedArticleIsAKeyAndAnotherProductGroupsQualifiersStayOut(): void
    {
        $links = $this->catalog->vehicleLinkCriteria(self::ESPACE, CarType::PassengerCar, [self::LUK, 1], self::FLYWHEEL + 1);

        $this->assertSame([self::LUK, 1], array_keys($links));
        $this->assertSame([], $links[self::LUK]->qualifierSets);
        $this->assertSame([], $links[1]->criteria());
    }

    public function testACommercialVehicleReadsTheCvLinks(): void
    {
        $this->assertSame([], $this->catalog->vehicleLinkCriteria(self::ESPACE, CarType::CommercialVehicle, [self::LUK], self::FLYWHEEL)[self::LUK]->qualifierSets ?? null);
    }
}
