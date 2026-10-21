<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Finder;

use App\Agent\Finder\FinderCategoryList;
use App\Catalog\PartsApiCatalog;
use App\PartsApi\CarType;
use App\PartsApi\PartsApiClient;
use App\Tests\Mock\FixtureHttpClient;
use App\Tests\Support\InMemoryPartsApiResponseStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FinderCategoryListTest extends TestCase
{
    public function testEveryLeafOfThe307TreeIsListedUnderItsBreadcrumb(): void
    {
        $list = $this->list(new FixtureHttpClient(\dirname(__DIR__, 3).'/Fixtures'))->forVehicle(16182, CarType::PassengerCar);
        $lines = explode("\n", $list);

        $this->assertContains('[Direction]', $lines);
        $this->assertContains('100198 Rotules', $lines);
        $this->assertContains('[Direction > Barre de connexion / composants]', $lines);
        $this->assertContains('100602 Kit de réparation', $lines);
        $this->assertContains('[Moteur > Distribution moteur > Courroie crantée/tension/guidage]', $lines);
        $this->assertContains('100404 Courroie crantée', $lines);
        $headers = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '[')));
        $this->assertSame(array_values(array_unique($headers)), $headers);
        $this->assertGreaterThan(array_search('[Direction]', $lines, true), array_search('100198 Rotules', $lines, true));
    }

    public function testNonLeafNodesToolsAndDiagramEntriesAreLeftOut(): void
    {
        $list = $this->list(new FixtureHttpClient(\dirname(__DIR__, 3).'/Fixtures'))->forVehicle(16182, CarType::PassengerCar);

        foreach ([100012, 100601, 100003, 706365, 706380] as $branch) {
            $this->assertStringNotContainsString((string) $branch, $list);
        }
        foreach ([706383, 706381, 706388, 103158, 706307, 103129, 101835, 102771, 101765] as $excluded) {
            $this->assertStringNotContainsString((string) $excluded, $list);
        }
        $this->assertStringNotContainsString('Outils', $list);
        $this->assertStringNotContainsString('graphique', $list);
        $this->assertStringNotContainsString('[Carrosserie]', $list);
    }

    public function testAnUnreachableCatalogueSaysSoInsteadOfThrowing(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error_code":5002,"message":"bad key"}', ['http_code' => 403]));

        $this->assertStringContainsString('indisponible', $this->list($http)->forVehicle(16182, CarType::PassengerCar));
    }

    private function list(HttpClientInterface $http): FinderCategoryList
    {
        return new FinderCategoryList(new PartsApiCatalog(new PartsApiClient($http, ['getSearchTree' => 'test-key'], new InMemoryPartsApiResponseStore()), 6));
    }
}
