<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bonneref;

use App\Bonneref\BonnerefClient;
use App\Bonneref\BonnerefServiceToken;
use App\Catalog\CarType;
use App\Exception\UpstreamException;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Security\OwnerContext;
use App\Tests\Mock\BonnerefHttpClient;
use App\Tests\Mock\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class BonnerefClientTest extends TestCase
{
    private BonnerefHttpClient $http;
    private BonnerefServiceToken $token;
    private RequestStack $requests;
    private LocaleContext $locale;
    private OwnerContext $owner;
    private BonnerefClient $client;
    private int $minted = 0;

    protected function setUp(): void
    {
        BonnerefHttpClient::reset();
        $this->http = new BonnerefHttpClient();
        $this->token = new BonnerefServiceToken(new MockHttpClient(fn (string $method, string $url): JsonMockResponse => str_ends_with($url, '/.well-known/openid-configuration')
            ? new JsonMockResponse(['token_endpoint' => 'https://casdoor.test/token'])
            : new JsonMockResponse(['access_token' => 'service-token-'.++$this->minted, 'expires_in' => 604800])), new ArrayAdapter(), 'https://casdoor.test', 'mpa-service-id', 'mpa-service-secret');
        $this->requests = new RequestStack();
        $this->locale = new LocaleContext($this->requests);
        $this->owner = new OwnerContext(new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => new TokenStorage()])));
        $this->owner->set('owner@example.com');
        $this->client = new BonnerefClient($this->http, $this->token, $this->owner, locale: $this->locale);
    }

    public function testMakesAreReadFromTheHydraMemberAndSentWithTheServiceTokenAndTheOwner(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->requests->push(Request::create('/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer caller-token']));

        $makes = $this->client->makes(CarType::CommercialVehicle, 'iv');

        $this->assertSame([3854, 55, 93, 72], array_map(static fn ($m) => $m->id, $makes));
        $this->assertSame('IVECO', $makes[1]->name);

        $sent = $this->http->lastRequest();
        $this->assertSame('/makes', $sent['path']);
        $this->assertSame(['carType' => 'CV', 'search' => 'iv'], $sent['query']);
        $this->assertSame('Bearer service-token-1', $sent['headers']['authorization'], 'the caller\'s bearer is never forwarded');
        $this->assertSame('owner@example.com', $sent['headers']['x-bonneref-owner']);
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

    public function testEveryPageOfACollectionCarriesTheServiceTokenAndTheOwner(): void
    {
        $this->http->route('/makes', self::page([['id' => 1, 'name' => 'AUDI']], '/makes?carType=PC&page=2'), query: ['carType' => 'PC']);
        $this->http->route('/makes', self::page([['id' => 2, 'name' => 'BMW']], null), query: ['carType' => 'PC', 'page' => '2']);

        $this->client->makes();

        $headers = array_column(BonnerefHttpClient::$requests, 'headers');
        $this->assertSame(['Bearer service-token-1', 'Bearer service-token-1'], array_column($headers, 'authorization'));
        $this->assertSame(['owner@example.com', 'owner@example.com'], array_column($headers, 'x-bonneref-owner'));
    }

    public function testWithoutAnOwnerNothingIsSent(): void
    {
        $this->http->fixture('/makes', 'makes');
        $this->owner->set(null);

        try {
            $this->client->makes();
            $this->fail('a catalogue call must not leave without an owner');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('owner', $e->getMessage());
        }

        $this->assertSame([], BonnerefHttpClient::$requests);
    }

    public function testARefusedServiceTokenIsMintedAgainOnTheNextCall(): void
    {
        $this->http->route('/makes', '{"title":"Unauthorized"}', 401);

        try {
            $this->client->makes();
            $this->fail('a 401 must surface');
        } catch (UpstreamException $e) {
            $this->assertFalse($e->transient);
        }

        $this->http->fixture('/makes', 'makes');
        $this->client->makes();

        $this->assertSame('Bearer service-token-2', $this->http->lastRequest()['headers']['authorization'] ?? null);
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
        $client = new BonnerefClient($this->http, $this->token, $this->owner, $logger);
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
