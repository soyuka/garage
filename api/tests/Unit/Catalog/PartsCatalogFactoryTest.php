<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\CatalogSource;
use App\Catalog\PartsApiCatalog;
use App\Catalog\PartsCatalog;
use App\Catalog\PartsCatalogFactory;
use App\Catalog\TecdocCatalog;
use App\PartsApi\PartsApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Yaml\Yaml;

final class PartsCatalogFactoryTest extends TestCase
{
    public function testPartsApiIsTheSourceByDefaultValue(): void
    {
        $this->assertSame(CatalogSource::PartsApi, CatalogSource::from('partsapi'));
        $this->assertSame(CatalogSource::Tecdoc, CatalogSource::from('tecdoc'));
    }

    public function testOnlyTheSelectedCatalogIsEverBuilt(): void
    {
        $built = [];
        $partsApi = $this->createStub(PartsCatalog::class);
        $tecdoc = $this->createStub(PartsCatalog::class);
        $locator = new ServiceLocator([
            CatalogSource::PartsApi->value => static function () use (&$built, $partsApi): PartsCatalog {
                $built[] = 'partsapi';

                return $partsApi;
            },
            CatalogSource::Tecdoc->value => static function () use (&$built, $tecdoc): PartsCatalog {
                $built[] = 'tecdoc';

                return $tecdoc;
            },
        ]);

        $this->assertSame($tecdoc, PartsCatalogFactory::create(CatalogSource::Tecdoc, $locator));
        $this->assertSame(['tecdoc'], $built);

        $this->assertSame($partsApi, PartsCatalogFactory::create(CatalogSource::PartsApi, $locator));
        $this->assertSame(['tecdoc', 'partsapi'], $built);
    }

    public function testTheContainerHandsTheFactoryALocatorAndTecdocTakesNoPartsApiService(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $factory = $services[PartsCatalog::class];
        $this->assertSame([PartsCatalogFactory::class, 'create'], $factory['factory']);
        $this->assertArrayNotHasKey('$partsApi', $factory['arguments']);
        $this->assertArrayNotHasKey('$tecdoc', $factory['arguments']);
        $this->assertSame(
            [CatalogSource::PartsApi->value => '@'.PartsApiCatalog::class, CatalogSource::Tecdoc->value => '@'.TecdocCatalog::class],
            $factory['arguments']['$catalogs']->getValue(),
        );

        $this->assertStringNotContainsString(PartsApiCatalog::class, json_encode($services[TecdocCatalog::class]));
        $this->assertStringNotContainsString(PartsApiClient::class, json_encode($services[TecdocCatalog::class]));
    }
}
