<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bonneref;

use App\Bonneref\BonnerefClient;
use App\Bonneref\BonnerefToken;
use App\Catalog\CarType;
use App\Exception\UpstreamException;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Tests\Mock\BonnerefHttpClient;
use App\Tests\Mock\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BonnerefClientTest extends TestCase
{
    private BonnerefHttpClient $http;
    private BonnerefToken $token;
    private RequestStack $requests;
    private LocaleContext $locale;
    private BonnerefClient $client;

    protected function setUp(): void
    {
        BonnerefHttpClient::reset();
        $this->http = new BonnerefHttpClient();
        $this->token = new BonnerefToken();
        $this->requests = new RequestStack();
        $this->locale = new LocaleContext($this->requests);
        $this->client = new BonnerefClient($this->http, $this->token, $this->requests, locale: $this->locale);
    }

    public function testMakesAreReadFromTheHydraMemberAndSentWithTheCurrentRequestsBearer(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->requests->push(Request::create('/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer caller-token']));

        $makes = $this->client->makes(CarType::CommercialVehicle, 'iv');

        $this->assertSame([3854, 55, 93, 72], array_map(static fn ($m) => $m->id, $makes));
        $this->assertSame('IVECO', $makes[1]->name);

        $sent = $this->http->lastRequest();
        $this->assertSame('/makes', $sent['path']);
        $this->assertSame(['carType' => 'CV', 'search' => 'iv'], $sent['query']);
        $this->assertSame('Bearer caller-token', $sent['headers']['authorization']);
        $this->assertSame('application/ld+json', $sent['headers']['accept']);
    }

    public function testTheCallersLanguageIsForwardedAndEnglishIsTheDefault(): void
    {
        $this->http->fixture('/makes', 'makes');

        $this->client->makes();
        $this->assertSame('en', $this->http->lastRequest()['headers']['accept-language']);

        $request = Request::create('/mcp');
        $request->setLocale('fr');
        $this->requests->push($request);
        $this->client->makes();
        $this->assertSame('fr', $this->http->lastRequest()['headers']['accept-language']);
    }

    public function testAWorkerTurnForwardsTheTurnsInterfaceLanguage(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->locale->set(Locale::En);

        $this->client->makes();

        $this->assertSame('en', $this->http->lastRequest()['headers']['accept-language']);
    }

    public function testEveryPageOfACollectionCarriesTheLanguage(): void
    {
        $this->locale->set(Locale::En);
        $this->http->route('/makes', self::page([['id' => 1, 'name' => 'AUDI']], '/makes?carType=PC&page=2'), query: ['carType' => 'PC']);
        $this->http->route('/makes', self::page([['id' => 2, 'name' => 'BMW']], null), query: ['carType' => 'PC', 'page' => '2']);

        $this->client->makes();

        $this->assertCount(2, BonnerefHttpClient::$requests);
        $this->assertSame(['en', 'en'], array_column(array_column(BonnerefHttpClient::$requests, 'headers'), 'accept-language'));
    }

    public function testTheTurnsTokenWinsOverARequestBearer(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->requests->push(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer request-token']));
        $this->token->set('turn-token');

        $this->client->makes();

        $this->assertSame('Bearer turn-token', $this->http->lastRequest()['headers']['authorization']);
    }

    public function testWithNeitherAHeaderNorATokenNothingIsForwarded(): void
    {
        $this->http->fixture('/makes', 'makes');

        $this->client->makes();

        $this->assertArrayNotHasKey('authorization', $this->http->lastRequest()['headers']);
    }

    public function testAClearedTurnTokenStopsBeingForwarded(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->token->set('turn-token');
        $this->token->set(null);

        $this->client->makes();

        $this->assertArrayNotHasKey('authorization', $this->http->lastRequest()['headers']);
    }

    public function testAnUnprefixedAuthorizationHeaderIsNotForwarded(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->requests->push(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Basic Zm9vOmJhcg==']));

        $this->client->makes();

        $this->assertArrayNotHasKey('authorization', $this->http->lastRequest()['headers']);
    }

    public function testACollectionFollowsHydraNextAcrossPages(): void
    {
        $this->http->route('/makes', self::page([['id' => 1, 'name' => 'AUDI']], '/makes?carType=PC&page=2'), query: ['carType' => 'PC']);
        $this->http->route('/makes', self::page([['id' => 2, 'name' => 'BMW']], null), query: ['carType' => 'PC', 'page' => '2']);

        $this->assertSame(['AUDI', 'BMW'], array_map(static fn ($m) => $m->name, $this->client->makes()));
        $this->assertSame(['carType' => 'PC', 'page' => '2'], $this->http->lastRequest()['query'] ?? null);
    }

    public function testANextLinkOffTheCollectionIsNotFollowed(): void
    {
        $this->http->route('/makes', self::page([['id' => 1, 'name' => 'AUDI']], 'https://elsewhere.example/steal?page=2'));

        $this->assertCount(1, $this->client->makes());
        $this->assertCount(1, BonnerefHttpClient::$requests);
    }

    public function testPagingIsBoundedAndSaysSo(): void
    {
        $logger = new RecordingLogger();
        $client = new BonnerefClient($this->http, $this->token, $this->requests, $logger);
        $this->http->route('/makes', self::page([['id' => 1, 'name' => 'AUDI']], '/makes?carType=PC&page=2'));

        $this->assertCount(BonnerefClient::MAX_PAGES, $client->makes());
        $this->assertCount(BonnerefClient::MAX_PAGES, BonnerefHttpClient::$requests);
        $this->assertSame(['warning'], array_column($logger->records, 'level'));
    }

    /**
     * @param list<array<string, mixed>> $members
     */
    private static function page(array $members, ?string $next): string
    {
        return json_encode(['hydra:member' => $members] + (null === $next ? [] : ['hydra:view' => ['hydra:next' => $next]]), \JSON_THROW_ON_ERROR);
    }

    public function testAMemberKeyWithoutThePrefixIsAccepted(): void
    {
        $this->http->route('/makes', json_encode(['member' => [['id' => 7, 'name' => 'SEAT']]], \JSON_THROW_ON_ERROR));

        $this->assertSame('SEAT', $this->client->makes()[0]->name);
    }

    public function testModelsAndCarsAreMapped(): void
    {
        $this->http->fixture('/makes/72/models', 'models_72');
        $this->http->fixture('/makes/72/models/4887/cars', 'cars_72_4887');

        $models = $this->client->models(72, CarType::PassengerCar);
        $row = array_values(array_filter($models, static fn ($m) => 4887 === $m->id))[0];
        $this->assertSame('6 Hatchback (GG)', $row->name);
        $this->assertSame('2002-08', $row->yearFrom);
        $this->assertSame('2008-12', $row->yearTo);

        $cars = $this->client->cars(72, 4887, CarType::PassengerCar);
        $this->assertCount(14, $cars);
        $this->assertSame(141, $cars[1]->powerPs);
        $this->assertSame(1999, $cars[1]->capacityCc);
    }

    public function testCarFiltersAreForwardedAndNullOnesAreNot(): void
    {
        $this->http->fixture('/makes/72/models/4887/cars', 'cars_72_4887');

        $this->client->cars(72, 4887, CarType::PassengerCar, year: 2005, capacityLitres: 2.0, fuel: 'diesel');

        $this->assertSame(
            ['carType' => 'PC', 'year' => '2005', 'capacityLitres' => '2', 'fuel' => 'diesel'],
            $this->http->lastRequest()['query'],
        );
    }

    public function testPartCategoriesCarryTheFlagsAsTheyAreAsked(): void
    {
        $this->http->fixture('/cars/34247/part-categories', 'categories_34247_all');

        $categories = $this->client->partCategories(34247, CarType::CommercialVehicle, false, 'filter');

        $this->assertCount(60, $categories);
        $this->assertSame(
            ['carType' => 'CV', 'leafOnly' => 'false', 'search' => 'filter'],
            $this->http->lastRequest()['query'],
        );
    }

    public function testAMotorbikeCatalogueCallSendsMc(): void
    {
        $this->http->fixture('/cars/101222/part-categories', 'categories_34247_all');

        $this->client->partCategories(101222, CarType::Motorbike, true, 'oil');

        $this->assertSame(
            ['carType' => 'MC', 'leafOnly' => 'true', 'search' => 'oil'],
            $this->http->lastRequest()['query'],
        );
    }

    public function testArticlesAreMapped(): void
    {
        $this->http->fixture('/cars/34247/part-categories/200158/articles', 'articles_34247_200158');

        $articles = $this->client->articles(34247, 200158, CarType::CommercialVehicle);

        $this->assertCount(4, $articles);
        $this->assertSame('EK-1522', $articles[0]->number);
        $this->assertSame('ELOFIC', $articles[0]->brand);
        $this->assertSame(6378, $articles[0]->supplierId);
    }

    public function testNarrowedKeepsEveryFieldTheContractCarriesAndDefaultsTheOmittedOnes(): void
    {
        $this->http->fixture('/cars/16182/part-categories/100198/narrowed', 'narrowed_16182_100198');

        $narrowed = $this->client->narrowed(16182, 100198, CarType::PassengerCar, 'PEUGEOT', 51);

        $this->assertTrue($narrowed->groupRequired);
        $this->assertSame([914, 51, 2066], array_column($narrowed->productGroups, 'id'));
        $this->assertSame(86, $narrowed->totalArticles);
        $this->assertNull($narrowed->degradedReason);
        $this->assertNull($narrowed->productGroup);
        $this->assertSame(
            ['carType' => 'PC', 'make' => 'PEUGEOT', 'productGroupId' => '51'],
            $this->http->lastRequest()['query'],
        );
    }

    public function testAPlateLookupMapsTheVehicle(): void
    {
        $this->http->fixture('/vehicles/XC302ZC', 'vehicle_XC302ZC');

        $vehicle = $this->client->vehicleByPlate('xc-302-zc');

        $this->assertSame('/vehicles/XC302ZC', $this->http->lastRequest()['path']);
        $this->assertSame('IVECO', $vehicle->make);
        $this->assertSame(34247, $vehicle->carId);
        $this->assertSame('CV', $vehicle->carType);
        $this->assertSame(2287, $vehicle->engineCapacityCcm);
    }

    public function testAnUnknownPlateIsNullNotAnError(): void
    {
        $this->http->route('/vehicles/ZZ999ZZ', '{}', 404);

        $this->assertNull($this->client->vehicleByPlate('ZZ-999-ZZ'));
    }

    public function testAServerErrorIsATransientUpstreamFailure(): void
    {
        $this->http->route('/makes', '{}', 503);

        $e = $this->failure(fn () => $this->client->makes());

        $this->assertTrue($e->transient);
        $this->assertSame('the bonneref catalog', $e->source);
    }

    public function testATransportFailureIsATransientUpstreamFailure(): void
    {
        $this->http->route('/vehicles/TT000TT', new TransportException('Idle timeout reached'));

        $e = $this->failure(fn () => $this->client->vehicleByPlate('TT000TT'));

        $this->assertTrue($e->transient);
    }

    public function testARejectedTokenIsADefinitiveFailure(): void
    {
        $this->http->route('/makes', '{}', 401);

        $this->assertFalse($this->failure(fn () => $this->client->makes())->transient);
    }

    public function testAnUnknownCatalogPathIsADefinitiveFailure(): void
    {
        $this->assertFalse($this->failure(fn () => $this->client->partCategories(1, CarType::PassengerCar))->transient);
    }

    private function failure(\Closure $call): UpstreamException
    {
        try {
            $call();
        } catch (UpstreamException $e) {
            return $e;
        }

        $this->fail('expected an UpstreamException');
    }
}
