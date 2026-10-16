<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\Narrowing\ArticleNarrower;
use App\Catalog\Narrowing\CarMakeResolver;
use App\Catalog\Narrowing\NarrowingResult;
use App\Catalog\PartsCatalog;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use App\Tests\Support\InMemoryPartsApiResponseStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArticleNarrowerTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/partsapi/';
    private const CAR_ID = 19107;
    private const CATEGORY_ID = 100259;

    /** @var list<string> */
    private array $requested = [];

    /** @var list<string> */
    private array $failing = [];

    /** @var list<int> */
    private array $requestedArtIds = [];

    private InMemoryPartsApiResponseStore $store;

    protected function setUp(): void
    {
        $this->requested = [];
        $this->failing = [];
        $this->requestedArtIds = [];
        $this->store = new InMemoryPartsApiResponseStore();
    }

    public function testTheMazdaOilFilterIsOnePartTheCarmakerSellsInTwoSizes(): void
    {
        $result = $this->narrow();

        $this->assertTrue($result->narrowed);
        $this->assertNull($result->degradedReason);
        $this->assertSame(220, $result->totalArticles);
        $this->assertSame(126, $result->totalBrands);

        $this->assertCount(1, $result->clusters);
        $part = $result->clusters[0];
        $numbers = array_column($part->variants, 'number', 'artId');
        $this->assertSame('W 712/73', $numbers[9674372]);
        $this->assertSame('LS1176', $numbers[27611375]);
        $this->assertSame('F 026 407 085', $numbers[6926893]);
        $this->assertSame('MANN-FILTER', $part->representative['brand']);

        // 85 mm and 93 mm filters both cross to MAZDA LF10-14-302: one cluster, so nothing to ask
        $this->assertSame([], $result->differsOn);
        $this->assertSame(220, $part->articleCount + $result->unclassifiedCount);
        $this->assertLessThan(110, $result->unclassifiedCount);

        $height = $part->fingerprint['hauteur'];
        $this->assertSame('93', $height['value']);
        $this->assertSame(85.0, $height['min']);
        $this->assertGreaterThanOrEqual(93.0, $height['max']);
        $this->assertSame('mm', $height['unit']);
        $this->assertSame('3/4-16', $part->fingerprint['thread']['value']);
        $this->assertTrue($part->fingerprint['pression_d_ouverture_clapet_de_derivation']['info']);
    }

    public function testApplicabilityIsUnionedPerClusterAndNeverSplits(): void
    {
        $result = $this->narrow();

        $this->assertCount(1, $result->clusters);
        $applicability = array_column($result->clusters[0]->applicability, 'values', 'name');

        $vin = $applicability['Numéro de châssis (VIN) à partir de'] ?? [];
        foreach (['130831', '176451', '195596', '306354'] as $breakpoint) {
            $this->assertContains($breakpoint, $vin);
        }
        $this->assertSame(\count($vin), \count(array_unique($vin)));
        $this->assertContains('200406', $applicability['Année à partir de'] ?? []);
        $this->assertContains('200907', $applicability['Année à partir de'] ?? []);
        $this->assertSame([], array_filter(array_merge(...array_values($applicability)), static fn (string $v): bool => '' === $v || str_contains($v, ';')));

        foreach (array_keys($applicability) as $name) {
            $this->assertDoesNotMatchRegularExpression('/code moteur|tecdoc du moteur|pour le mod|type de v/iu', $name);
        }
    }

    public function testApplicabilityDoesNotSplitWithoutTheMakeEither(): void
    {
        $clusters = $this->narrow(make: null)->clusters;

        $this->assertGreaterThan(1, \count($clusters));
        foreach ($clusters as $cluster) {
            $this->assertNotSame([], $cluster->applicability);
        }
    }

    public function testWithoutTheCarsMakeNothingMerges(): void
    {
        $result = $this->narrow(make: null);

        $this->assertGreaterThan(1, \count($result->clusters));
        $this->assertNotSame([], $result->differsOn);
    }

    public function testOeNumbersComeFromVehicleMakersOnly(): void
    {
        $oe = $this->narrow()->clusters[0]->oeNumbers;

        $this->assertContains(['brand' => 'MAZDA', 'number' => 'LF10-14-302'], $oe);
        $this->assertContains(['brand' => 'FORD', 'number' => '1S7G-6714-CA'], $oe);
        $this->assertContains(['brand' => 'VOLVO', 'number' => '30711781'], $oe);
        $this->assertNotContains('BOSCH', array_column($oe, 'brand'));
        $this->assertNotContains('MANN-FILTER', array_column($oe, 'brand'));
        $this->assertSame($oe, array_values(array_unique($oe, \SORT_REGULAR)));
    }

    public function testOtherMakersOeNumbersNeverDriveAMerge(): void
    {
        // FORD is a vehicle maker in the list but not this car's: only MAZDA numbers may merge clusters
        $result = $this->narrow(make: 'FORD');

        $this->assertGreaterThan(1, \count($result->clusters));
    }

    public function testTheCarsOwnMakeCountsEvenWhenTheMakesListMissesIt(): void
    {
        $result = $this->narrow(makes: [], make: 'mazda');

        $this->assertContains('MAZDA', array_column($result->clusters[0]->oeNumbers, 'brand'));
        $this->assertNotContains('FORD', array_column($result->clusters[0]->oeNumbers, 'brand'));
    }

    public function testVariantsAreTierRankedAndCarryTheirTier(): void
    {
        $variants = $this->narrow()->clusters[0]->variants;

        $this->assertSame('MANN-FILTER', $variants[0]['brand']);
        $this->assertSame(1, $variants[0]['tier']);
        $tiers = array_column($variants, 'tier');
        $sorted = $tiers;
        sort($sorted);
        $this->assertSame($sorted, $tiers);
    }

    public function testColdCacheCallBudgetNeverTouchesMedia(): void
    {
        $result = $this->narrow();

        $counts = array_count_values($this->requested);
        $this->assertSame(1, $counts['getArticles']);
        $this->assertLessThanOrEqual(ArticleNarrower::MAX_REPRESENTATIVES + ArticleNarrower::MAX_SIBLING_PROBES, $counts['getArticleCriteria']);
        $this->assertLessThanOrEqual(8, $counts['getArticleCrosses']);
        $this->assertArrayNotHasKey('getArticleMedia', $counts);
        $this->assertSame(\count($this->requested), $result->upstreamCalls);
    }

    public function testASecondRunSpendsNoUpstreamCall(): void
    {
        $this->narrow();
        $spent = \count($this->requested);

        $again = $this->narrow();

        $this->assertSame(0, $again->upstreamCalls);
        $this->assertCount($spent, $this->requested);
        $this->assertCount(1, $again->clusters);
    }

    public function testArticleFactsAreCachedForeverWhileTheCatalogSnapshotExpires(): void
    {
        $this->narrow();

        $criteria = $this->store->entries['getArticleCriteria.'.sha1('getArticleCriteria?ART_ID=9674372&LANG=6')] ?? null;
        $this->assertNotNull($criteria);
        $this->assertNull($criteria['expiresAt']);

        $articles = $this->store->entries['getArticles.'.sha1('getArticles?carId=19107&carType=PC&lang=6&strId=100259')] ?? null;
        $this->assertNotNull($articles);
        $this->assertNotNull($articles['expiresAt']);
    }

    public function testDegradesWhenCrossesQuotaIsExhausted(): void
    {
        $this->failing = ['getArticleCrosses'];

        $result = $this->narrow();

        $this->assertFalse($result->narrowed);
        $this->assertStringContainsString('cross-references', (string) $result->degradedReason);
        // without crosses there is no OE evidence to merge the sizes, and nothing claims the other articles
        $this->assertGreaterThan(1, \count($result->clusters));
        $this->assertSame([], $result->clusters[0]->oeNumbers);
        $this->assertSame(8, array_sum(array_column($result->clusters, 'articleCount')));
        $this->assertSame(220 - 8, $result->unclassifiedCount);
        // the quota is gone for the method: no point spending a call per remaining cluster
        $this->assertSame(1, array_count_values($this->requested)['getArticleCrosses']);
    }

    public function testDegradesToBrandGroupingWhenCriteriaQuotaIsExhausted(): void
    {
        $this->failing = ['getArticleCriteria'];

        $result = $this->narrow();

        $this->assertFalse($result->narrowed);
        $this->assertStringContainsString('criteria', (string) $result->degradedReason);
        $this->assertSame([], $result->clusters);
        $this->assertSame(220, $result->unclassifiedCount);
        $this->assertNotNull($result->fallbackBrands);
        $this->assertSame('MANN-FILTER', $result->fallbackBrands[0]['brand']);
        $this->assertSame(1, array_count_values($this->requested)['getArticleCriteria']);
        $this->assertArrayNotHasKey('getArticleCrosses', array_count_values($this->requested));
    }

    public function testToArrayExposesTheDocumentedShape(): void
    {
        $data = $this->narrow()->toArray();

        $this->assertEqualsCanonicalizing(
            ['clusters', 'totalArticles', 'totalBrands', 'unclassifiedCount', 'differsOn', 'narrowed', 'degradedReason', 'upstreamCalls', 'fallbackBrands', 'applicabilityScope', 'productGroup', 'productGroups', 'groupRequired'],
            array_keys($data),
        );
        $this->assertSame('article', $data['applicabilityScope']);
        $this->assertEqualsCanonicalizing(
            ['id', 'fingerprint', 'oeNumbers', 'representative', 'variants', 'articleCount', 'applicability', 'productGroup'],
            array_keys($data['clusters'][0]),
        );
        $this->assertEqualsCanonicalizing(['artId', 'brand', 'number'], array_keys($data['clusters'][0]['representative']));
        $this->assertEqualsCanonicalizing(['artId', 'brand', 'number', 'tier'], array_keys($data['clusters'][0]['variants'][0]));
    }

    public function testACategoryMixingProductGroupsListsThemWithoutSpendingArticleCalls(): void
    {
        $result = $this->narrowRotules();

        $this->assertTrue($result->groupRequired);
        $this->assertNull($result->productGroup);
        $this->assertSame([], $result->clusters);
        $this->assertSame([], $result->differsOn);
        $this->assertSame(86, $result->totalArticles);
        $this->assertSame([
            ['id' => 914, 'name' => 'Rotule de barre de connexion', 'articleCount' => 52],
            ['id' => 51, 'name' => 'Rotule de direction intérieure, barre de connexion', 'articleCount' => 32],
            ['id' => 2066, 'name' => 'Rotule de direction', 'articleCount' => 2],
        ], $result->productGroups);
        $this->assertSame(['getArticles'], $this->requested);
        $this->assertSame(1, $result->upstreamCalls);
    }

    public function testNarrowsInsideTheChosenProductGroupOnly(): void
    {
        $result = $this->narrowRotules(productGroupId: 51);

        $this->assertFalse($result->groupRequired);
        $this->assertSame(['id' => 51, 'name' => 'Rotule de direction intérieure, barre de connexion'], $result->productGroup);
        $this->assertSame(32, $result->totalArticles);
        $this->assertCount(3, $result->productGroups);

        $inner = $this->articleIdsOfGroup(51);
        $this->assertNotSame([], $this->requestedArtIds);
        $this->assertSame([], array_values(array_diff($this->requestedArtIds, $inner)));
        $this->assertContains(1757415, $this->requestedArtIds);
    }

    public function testAProductGroupTheCategoryDoesNotHoldYieldsNoArticle(): void
    {
        $result = $this->narrowRotules(productGroupId: 7);

        $this->assertFalse($result->groupRequired);
        $this->assertNull($result->productGroup);
        $this->assertSame(0, $result->totalArticles);
        $this->assertSame([], $result->clusters);
        $this->assertCount(3, $result->productGroups);
        $this->assertSame(['getArticles'], $this->requested);
    }

    public function testASingleGroupCategoryNarrowsWithoutBeingGivenTheGroup(): void
    {
        $result = $this->narrow();

        $this->assertFalse($result->groupRequired);
        $this->assertSame(['id' => 7, 'name' => 'Oil Filter'], $result->productGroup);
        $this->assertSame([['id' => 7, 'name' => 'Oil Filter', 'articleCount' => 220]], $result->productGroups);
        $this->assertSame(['id' => 7, 'name' => 'Oil Filter'], $result->clusters[0]->productGroup);
    }

    public function testABrandsSecondArticleLeftUnclaimedIsProbedAndKeptApartWhenTheCarmakerNumbersItDifferently(): void
    {
        // Real 307 data (2026-10-16): every top brand sells two inner axial joints for this car. BOSCH 0 986 141 079
        // (338 mm) crosses to PEUGEOT 3812.E0 with FEBI 19935 (OPR up to 10310); its sibling 0 986 141 133 (358 mm)
        // crosses to PEUGEOT 3812 E5 with FEBI 29625 (OPR 10311). Same thread, so only the carmaker numbers tell them apart.
        $result = $this->narrowRotules(productGroupId: 51);

        $this->assertCount(2, $result->clusters);
        [$early, $late] = $result->clusters;
        $this->assertSame('0 986 141 079', $early->representative['number']);
        $this->assertSame('0 986 141 133', $late->representative['number']);
        $this->assertContains('19935', array_column($early->variants, 'number'));
        $this->assertContains('29625', array_column($late->variants, 'number'));
        $this->assertNotContains('29625', array_column($early->variants, 'number'));

        $earlyOe = array_map(static fn (string $n): string => str_replace(['.', ' '], '', $n), array_column($early->oeNumbers, 'number'));
        $lateOe = array_map(static fn (string $n): string => str_replace(['.', ' '], '', $n), array_column($late->oeNumbers, 'number'));
        $this->assertContains('3812E0', $earlyOe);
        $this->assertContains('3812E5', $lateOe);
        $this->assertSame([], array_values(array_intersect($earlyOe, $lateOe)));
        $this->assertContains('10310', array_column($early->applicability, 'values', 'name')['pour numéro OPR jusqu’à'] ?? []);

        $counts = array_count_values($this->requested);
        $this->assertSame(ArticleNarrower::MAX_REPRESENTATIVES + ArticleNarrower::MAX_SIBLING_PROBES, $counts['getArticleCriteria']);
        $this->assertSame(3, $counts['getArticleCrosses']);
    }

    public function testClustersAlreadyApartListTheLengthThatTellsThemApart(): void
    {
        // Split by disjoint PEUGEOT OE only; the joint length (BOSCH spells it « Longeur rotule ») still tells them apart
        // with a tape measure: 322–338 mm (DELPHI states 322 there) against 358 mm.
        $result = $this->narrowRotules(productGroupId: 51);

        $this->assertCount(2, $result->clusters);
        $this->assertSame(['longeur_rotule'], array_column($result->differsOn, 'key'));
        $byCluster = array_column($result->differsOn[0]['values'], null, 'clusterId');
        $this->assertSame([322.0, 338.0], [$byCluster['c1']['min'], $byCluster['c1']['max']]);
        $this->assertSame([358.0, 358.0], [$byCluster['c2']['min'], $byCluster['c2']['max']]);
    }

    public function testAVariantsAlreadyCachedCriteriaJoinItsClustersApplicabilityForFree(): void
    {
        $this->warmCriteria(2472844);

        $result = $this->narrowRotules(productGroupId: 51);

        $this->assertSame(['10311'], array_column($result->clusters[1]->applicability, 'values', 'name')['pour n° OPR (date de fabrication du véhicule)'] ?? null);
        $this->assertNotContains(2472844, $this->requestedArtIds);
    }

    public function testWithoutTheCarsMakeTheProbeJoinsThePartItMatches(): void
    {
        $result = $this->narrow(make: null, carId: 16182, categoryId: 100198, productGroupId: 51);

        $this->assertNotContains('29625', array_map(static fn ($c): string => $c->representative['number'], $result->clusters));
        $this->assertContains('29625', array_merge(...array_map(static fn ($c): array => array_column($c->variants, 'number'), $result->clusters)));
    }

    public function testTheCarsMakeIsResolvedFromItsCarIdWhenTheClientGivesNone(): void
    {
        $this->warmCriteria(2472844);

        $result = $this->narrowRotules(productGroupId: 51, make: null, resolvedMake: 'PEUGEOT');

        $this->assertCount(2, $result->clusters);
        $this->assertContains('3812.E0', $this->oeOf($result, 0));
        $this->assertNotContains('3812 E5', $this->oeOf($result, 0));
        $this->assertContains('3812 E5', $this->oeOf($result, 1));
        $this->assertNotContains('3812.E0', $this->oeOf($result, 1));
        $this->assertEquals($this->narrowRotules(productGroupId: 51)->clusters, $result->clusters);
    }

    public function testTheResolvedMakeWinsOverAContradictingClientMake(): void
    {
        $result = $this->narrowRotules(productGroupId: 51, make: 'MAZDA', resolvedMake: 'PEUGEOT');

        $this->assertCount(2, $result->clusters);
    }

    /**
     * @return list<string>
     */
    private function oeOf(NarrowingResult $result, int $cluster): array
    {
        return array_column($result->clusters[$cluster]->oeNumbers, 'number');
    }

    private function warmCriteria(int $artId): void
    {
        $this->store->save('getArticleCriteria.'.sha1('getArticleCriteria?ART_ID='.$artId.'&LANG=6'), 'getArticleCriteria', ['ART_ID' => $artId, 'LANG' => 6], json_decode(self::fixture(sprintf('getArticleCriteria_%d.json', $artId)), true), null);
    }

    private function narrowRotules(?int $productGroupId = null, ?string $make = 'PEUGEOT', ?string $resolvedMake = null): NarrowingResult
    {
        return $this->narrow(makes: [['makeId' => 88, 'makeName' => 'PEUGEOT'], ['makeId' => 35, 'makeName' => 'CITROËN']], make: $make, carId: 16182, categoryId: 100198, productGroupId: $productGroupId, resolvedMake: $resolvedMake);
    }

    /**
     * @return list<int>
     */
    private function articleIdsOfGroup(int $productTypeId): array
    {
        $rows = json_decode(self::fixture('getArticles_16182_PC_100198.json'), true);

        return array_values(array_map(static fn (array $r): int => $r['ART_ID'], array_filter($rows, static fn (array $r): bool => $productTypeId === $r['PT_ID'])));
    }

    /**
     * @param list<array{makeId: int, makeName: string}> $makes
     */
    private function narrow(array $makes = [['makeId' => 1, 'makeName' => 'FORD'], ['makeId' => 2, 'makeName' => 'VOLVO']], ?string $make = 'MAZDA', int $carId = self::CAR_ID, int $categoryId = self::CATEGORY_ID, ?int $productGroupId = null, ?string $resolvedMake = null): NarrowingResult
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($makes): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $name = (string) $query['method'];
            $this->requested[] = $name;
            if (isset($query['ART_ID'])) {
                $this->requestedArtIds[] = (int) $query['ART_ID'];
            }

            if (\in_array($name, $this->failing, true)) {
                return new MockResponse((string) json_encode(['error_code' => 5005, 'message' => 'Quota exceeded', 'status' => 500]), ['http_code' => 500]);
            }

            $body = match ($name) {
                'getMakes' => json_encode($makes),
                'getArticles' => self::fixture(sprintf('getArticles_%s_%s_%s.json', $query['carId'], $query['carType'], $query['strId'])),
                default => self::fixture(sprintf('%s_%s.json', $name, $query['ART_ID'] ?? '')),
            };

            return new MockResponse((string) $body, ['response_headers' => ['content-type' => 'application/json']]);
        });

        $client = new PartsApiClient($http, array_fill_keys(['getMakes', 'getArticles', 'getArticleCriteria', 'getArticleCrosses'], 'test-key'), $this->store);
        $resolver = null === $resolvedMake ? null : new class($resolvedMake) implements CarMakeResolver {
            public function __construct(private readonly string $make)
            {
            }

            public function make(int $carId, CarType $carType): string
            {
                return $this->make;
            }
        };
        $narrower = new ArticleNarrower(new PartsCatalog($client, 6), makes: $resolver);

        return $narrower->narrow($carId, CarType::PassengerCar, $categoryId, $make, $productGroupId);
    }

    private static function fixture(string $file): string
    {
        if (is_file(self::FIXTURES.$file)) {
            return (string) file_get_contents(self::FIXTURES.$file);
        }

        return '[]';
    }
}
