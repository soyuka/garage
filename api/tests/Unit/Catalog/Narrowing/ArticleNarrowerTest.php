<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Narrowing;

use App\Catalog\Narrowing\ArticleNarrower;
use App\Catalog\Narrowing\NarrowingResult;
use App\Catalog\PartsCatalog;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->requested = [];
        $this->failing = [];
        $this->cache = new ArrayAdapter();
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
        $this->assertLessThanOrEqual(8, $counts['getArticleCriteria']);
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

        $criteria = $this->cache->getItem('getArticleCriteria.'.sha1('getArticleCriteria?ART_ID=9674372&LANG=6'));
        $this->assertTrue($criteria->isHit());
        $this->assertArrayNotHasKey('expiry', $criteria->getMetadata());

        $articles = $this->cache->getItem('getArticles.'.sha1('getArticles?carId=19107&carType=PC&lang=6&strId=100259'));
        $this->assertTrue($articles->isHit());
        $this->assertArrayHasKey('expiry', $articles->getMetadata());
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
            ['clusters', 'totalArticles', 'totalBrands', 'unclassifiedCount', 'differsOn', 'narrowed', 'degradedReason', 'upstreamCalls', 'fallbackBrands'],
            array_keys($data),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'fingerprint', 'oeNumbers', 'representative', 'variants', 'articleCount'],
            array_keys($data['clusters'][0]),
        );
        $this->assertEqualsCanonicalizing(['artId', 'brand', 'number'], array_keys($data['clusters'][0]['representative']));
        $this->assertEqualsCanonicalizing(['artId', 'brand', 'number', 'tier'], array_keys($data['clusters'][0]['variants'][0]));
    }

    /**
     * @param list<array{makeId: int, makeName: string}> $makes
     */
    private function narrow(array $makes = [['makeId' => 1, 'makeName' => 'FORD'], ['makeId' => 2, 'makeName' => 'VOLVO']], ?string $make = 'MAZDA'): NarrowingResult
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($makes): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $name = (string) $query['method'];
            $this->requested[] = $name;

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

        $client = new PartsApiClient($http, array_fill_keys(['getMakes', 'getArticles', 'getArticleCriteria', 'getArticleCrosses'], 'test-key'), $this->cache);
        $narrower = new ArticleNarrower(new PartsCatalog($client, 6));

        return $narrower->narrow(self::CAR_ID, CarType::PassengerCar, self::CATEGORY_ID, $make);
    }

    private static function fixture(string $file): string
    {
        if (is_file(self::FIXTURES.$file)) {
            return (string) file_get_contents(self::FIXTURES.$file);
        }

        return '[]';
    }
}
