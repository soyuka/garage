<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\ArticleCriterion;
use App\Catalog\Narrowing\ArticleClusterer;
use App\Catalog\Narrowing\FingerprintBuilder;
use App\Catalog\Narrowing\Representative;
use PHPUnit\Framework\TestCase;

final class ArticleClustererTest extends TestCase
{
    public function testMannAndPurfluxLandInTwoClustersDifferingOnHeight(): void
    {
        $clusters = (new ArticleClusterer())->cluster([self::mann(), self::purflux()]);

        $this->assertCount(2, $clusters);

        $differs = (new ArticleClusterer())->differsOn($clusters);
        $byKey = array_column($differs, null, 'key');

        $this->assertArrayHasKey('hauteur', $byKey);
        $this->assertSame('Hauteur [mm]', $byKey['hauteur']['label']);
        $this->assertSame(
            [$clusters[0]->id => '93', $clusters[1]->id => '85'],
            array_column($byKey['hauteur']['values'], 'value', 'clusterId'),
        );
        $this->assertArrayNotHasKey('thread', $byKey);
        $this->assertArrayNotHasKey('diametre_exterieur', $byKey);
    }

    public function testSameDimensionsJoinTheExistingCluster(): void
    {
        $synthetic = self::representative(1, 'ACME', 'A1', 5000, [
            new ArticleCriterion('Diamètre extérieur [mm]', '76'),
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion('Filetage', '3/4"-16'),
        ]);

        $clusters = (new ArticleClusterer())->cluster([self::mann(), self::purflux(), $synthetic]);

        $this->assertCount(2, $clusters);
        $this->assertEqualsCanonicalizing([9674372, 1], array_map(static fn (Representative $r): int => $r->artId, $clusters[0]->members));
    }

    public function testToleranceIsTwoMillimetresOrTwoAndAHalfPercentOnMmCriteria(): void
    {
        $near = self::representative(2, 'ACME', 'A2', 5000, [new ArticleCriterion('Hauteur [mm]', '95')]);
        $far = self::representative(3, 'ACME', 'A3', 5000, [new ArticleCriterion('Hauteur [mm]', '96')]);
        $tall = self::representative(4, 'ACME', 'A4', 5001, [new ArticleCriterion('Hauteur [mm]', '200')]);
        $tallNear = self::representative(5, 'ACME', 'A5', 5002, [new ArticleCriterion('Hauteur [mm]', '204,5')]);

        $this->assertCount(1, (new ArticleClusterer())->cluster([$tall, $tallNear]));

        $this->assertCount(1, (new ArticleClusterer())->cluster([self::mann(), $near]));
        $this->assertCount(2, (new ArticleClusterer())->cluster([self::mann(), $far]));
    }

    public function testInfoOnlyCriteriaNeverSplitAndNeverDiffer(): void
    {
        $a = self::representative(1, 'ACME', 'A1', 1000, [
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion("Pression d'ouverture clapet de dérivation [ba", '1'),
            new ArticleCriterion('Diamètre 3 [mm]', '62'),
        ]);
        $b = self::representative(2, 'ACME', 'A2', 1001, [
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion("Pression d'ouverture clapet de dérivation [ba", '2,4'),
            new ArticleCriterion('Diamètre 3 [mm]', '72'),
        ]);

        $clusters = (new ArticleClusterer())->cluster([$a, $b]);

        $this->assertCount(1, $clusters);
        $this->assertSame([], (new ArticleClusterer())->differsOn($clusters));
    }

    public function testClustersAlreadyApartAlsoReportTheMillimetreInfoMeasuresTheyDisagreeOn(): void
    {
        $short = self::representative(1, 'ACME', 'A1', 1000, [
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion('Longueur [mm]', '340'),
            new ArticleCriterion('Largeur [mm]', '36'),
            new ArticleCriterion('Poids [kg]', '0,55'),
            new ArticleCriterion("Longueur de l'emballage [mm]", '80'),
        ]);
        $long = self::representative(2, 'ACME', 'A2', 1001, [
            new ArticleCriterion('Hauteur [mm]', '120'),
            new ArticleCriterion('Longueur [mm]', '359'),
            new ArticleCriterion('Largeur [mm]', '37'),
            new ArticleCriterion('Poids [kg]', '0,61'),
            new ArticleCriterion("Longueur de l'emballage [mm]", '95'),
        ]);

        $clusters = (new ArticleClusterer())->cluster([$short, $long]);
        $differs = (new ArticleClusterer())->differsOn($clusters);

        $this->assertCount(2, $clusters);
        $this->assertSame(['hauteur', 'longueur'], array_column($differs, 'key'));
        $this->assertSame([$clusters[0]->id => '340', $clusters[1]->id => '359'], array_column($differs[1]['values'], 'value', 'clusterId'));
    }

    public function testAnInfoMeasureNeverSplits(): void
    {
        $short = self::representative(1, 'ACME', 'A1', 1000, [new ArticleCriterion('Hauteur [mm]', '93'), new ArticleCriterion('Longueur [mm]', '340')]);
        $long = self::representative(2, 'ACME', 'A2', 1001, [new ArticleCriterion('Hauteur [mm]', '93'), new ArticleCriterion('Longueur [mm]', '359')]);

        $this->assertCount(1, (new ArticleClusterer())->cluster([$short, $long]));
    }

    public function testClusterFingerprintReportsTheRangeOfItsMembers(): void
    {
        $a = self::representative(1, 'ACME', 'A1', 1000, [new ArticleCriterion('Hauteur [mm]', '93')]);
        $b = self::representative(2, 'ACME', 'A2', 1001, [new ArticleCriterion('Hauteur [mm]', '94,5')]);

        $entry = (new ArticleClusterer())->cluster([$a, $b])[0]->merged()->entries['hauteur'];

        $this->assertSame('93', $entry->value);
        $this->assertSame(93.0, $entry->min);
        $this->assertSame(94.5, $entry->max);
        $this->assertSame(
            ['label' => 'Hauteur [mm]', 'value' => '93', 'min' => 93.0, 'max' => 94.5, 'unit' => 'mm'],
            $entry->toArray(),
        );
    }

    public function testApplicationLevelCriteriaNeverSplit(): void
    {
        $a = self::representative(4, 'ACME', 'A4', 5000, [
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion('Code moteur', 'L501'),
            new ArticleCriterion('Année à partir de', '200304'),
        ]);
        $b = self::representative(5, 'ACME', 'A5', 5001, [
            new ArticleCriterion('Hauteur [mm]', '93'),
            new ArticleCriterion('Code moteur', 'Perkins 404D-22'),
            new ArticleCriterion('Année à partir de', '201006; 201101'),
        ]);

        $this->assertCount(1, (new ArticleClusterer())->cluster([$a, $b]));
    }

    public function testAKeyMissingOnOneSideDoesNotSplit(): void
    {
        $bare = self::representative(6, 'ACME', 'A6', 5000, [new ArticleCriterion('Hauteur [mm]', '93')]);

        $this->assertCount(1, (new ArticleClusterer())->cluster([self::mann(), $bare]));
    }

    public function testRepresentativesWithoutPhysicalDataAreNotClustered(): void
    {
        $empty = self::representative(7, 'ACME', 'A7', 5000, []);

        $this->assertCount(1, (new ArticleClusterer())->cluster([self::mann(), $empty]));
    }

    public function testResultDoesNotDependOnInputOrder(): void
    {
        $reps = [
            self::mann(),
            self::purflux(),
            self::representative(1, 'ACME', 'A1', 5000, [new ArticleCriterion('Hauteur [mm]', '93')]),
            self::representative(2, 'OTHER', 'B1', 5000, [new ArticleCriterion('Hauteur [mm]', '85')]),
        ];

        $forward = self::shape((new ArticleClusterer())->cluster($reps));
        $backward = self::shape((new ArticleClusterer())->cluster(array_reverse($reps)));

        $this->assertSame($forward, $backward);
    }

    public function testClustersNeverChainThroughAMissingKey(): void
    {
        $tall = self::representative(1, 'ACME', 'A1', 1000, [new ArticleCriterion('Hauteur [mm]', '93')]);
        $short = self::representative(2, 'ACME', 'A2', 1001, [new ArticleCriterion('Hauteur [mm]', '85')]);
        $bridge = self::representative(3, 'ACME', 'A3', 1002, [new ArticleCriterion('Diamètre extérieur [mm]', '76')]);

        $clusters = (new ArticleClusterer())->cluster([$tall, $short, $bridge]);

        $this->assertCount(2, $clusters);
    }

    /**
     * @param list<\App\Catalog\Narrowing\Cluster> $clusters
     *
     * @return list<list<int>>
     */
    private static function shape(array $clusters): array
    {
        $shape = array_map(static function ($cluster): array {
            $ids = array_map(static fn (Representative $r): int => $r->artId, $cluster->members);
            sort($ids);

            return $ids;
        }, $clusters);

        return $shape;
    }

    private static function mann(): Representative
    {
        return self::fromFixture(9674372, 'MANN-FILTER', 'W 712/73', 0, 'getArticleCriteria_9674372.json');
    }

    private static function purflux(): Representative
    {
        return self::fromFixture(27611375, 'PURFLUX', 'LS1176', 3, 'getArticleCriteria_27611375.json');
    }

    private static function fromFixture(int $artId, string $brand, string $number, int $rank, string $file): Representative
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/catalog-rows/'.$file), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($rows);

        return self::representative($artId, $brand, $number, $rank, array_values(array_map(ArticleCriterion::fromCatalogRow(...), $rows)));
    }

    /**
     * @param list<ArticleCriterion> $criteria
     */
    private static function representative(int $artId, string $brand, string $number, int $rank, array $criteria): Representative
    {
        return new Representative($artId, $brand, $number, $rank, FingerprintBuilder::fromCriteria($criteria));
    }
}
