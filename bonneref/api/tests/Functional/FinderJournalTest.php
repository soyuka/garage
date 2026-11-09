<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnKind;
use App\Entity\AgentTurnStatus;
use App\Entity\AgentTurnToolCall;
use App\Mcp\ToolError;
use App\Repository\AgentTurnRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

final class FinderJournalTest extends AppTestCase
{
    public function testTheListingFiltersBySurface(): void
    {
        $client = static::createClient();
        $this->seed('filtre à huile', TurnSurface::Finder);

        $finder = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame(['filtre à huile'], array_column($finder, 'instruction'));
        $this->assertSame('finder', $finder[0]['surface']);
        $this->assertSame('agent', $finder[0]['kind']);
        $this->assertNull($finder[0]['route'] ?? null, 'JSON-LD skips null values: no vehicle, no route key');
        $this->assertNull($finder[0]['vehicle'] ?? null);

        $all = $this->members($client->request('GET', '/agent/turns', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertCount(1, $all);
    }

    public function testTheTurnExposesCacheTokens(): void
    {
        $client = static::createClient();
        $turn = $this->seed('filtre à huile', TurnSurface::Finder, static fn (AgentTurn $t) => $t->addUsage(8, 250, 12000, 3000));

        $summary = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray())[0];
        $this->assertSame(15258, $summary['totalTokens']);
        $this->assertSame(12000, $summary['cacheReadTokens']);
        $this->assertSame(3000, $summary['cacheCreationTokens']);

        $item = $client->request('GET', '/agent/turns/'.(string) $turn->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();
        $this->assertSame(8, $item['promptTokens']);
        $this->assertSame(250, $item['completionTokens']);
        $this->assertSame(12000, $item['cacheReadTokens']);
        $this->assertSame(3000, $item['cacheCreationTokens']);
        $this->assertEqualsWithDelta(0.012416, $item['estimatedCostUsd'], 1e-9, 'prices pinned in .env.test');
    }

    public function testATurnOnAnUnpricedModelHasNoCost(): void
    {
        $client = static::createClient();
        $turn = $this->seed('filtre à huile', TurnSurface::Finder, static fn (AgentTurn $t) => $t->addUsage(8, 250, 12000, 3000), model: 'claude-haiku-4-5');

        $item = $client->request('GET', '/agent/turns/'.(string) $turn->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();
        $this->assertSame('claude-haiku-4-5', $item['model']);
        $this->assertNull($item['estimatedCostUsd'] ?? null);
    }

    public function testAnAuthenticatedFinderSearchIsJournaled(): void
    {
        $client = static::createClient();

        $narrowed = $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre à huile'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(AgentTurnKind::Search, $row->getKind());
        $this->assertSame(AgentTurnStatus::Completed, $row->getStatus());
        $this->assertSame('filtre à huile', $row->getInstruction());
        $this->assertNull($row->getPartsApiCalls(), 'no partsapi call is made since the catalogue is local');
        $this->assertNotNull($row->getDurationMs());
        $this->assertSame([
            'rule' => 'category',
            'carId' => 19107,
            'carType' => 'PC',
            'categoryId' => 100259,
            'clusterCount' => \count($narrowed['clusters']),
            'totalArticles' => $narrowed['totalArticles'],
            'unclassifiedCount' => $narrowed['unclassifiedCount'],
            'outcome' => $narrowed['narrowed'] ? 'ok' : 'degraded',
            'httpStatus' => 200,
        ], $row->getSearch());

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('search', $listed[0]['kind']);
        $this->assertNull($listed[0]['partsApiCalls']);
        $this->assertSame('category', $listed[0]['search']['rule']);
        $this->assertSame('k/19107-PC/c/100259', $listed[0]['route']);
        $this->assertSame(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => null, 'model' => null, 'engine' => null], $listed[0]['vehicle']);
    }

    public function testANarrowedSearchCarriesTheCategoryLabelFromTheCachedTree(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cars/19107/part-categories?carType=PC', ['headers' => self::authHeaders()]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'url', 'Search-Input' => '100259'],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertSame('Filtre à huile', $this->rows()[0]->getCategoryLabel());
        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('Filtre à huile', $listed[0]['categoryLabel']);
        $item = $client->request('GET', $listed[0]['@id'], ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();
        $this->assertSame('Filtre à huile', $item['categoryLabel']);
    }

    public function testAnAgentTurnCarriesTheLastShownCategoryLabel(): void
    {
        $client = static::createClient();
        $this->seed('rotule', TurnSurface::Finder, static function (AgentTurn $t): void {
            $t->addToolCall(new AgentTurnToolCall(0, 'show_parts', ['categoryId' => 1, 'categoryLabel' => 'Silentbloc', 'rationale' => 'x'], '{"status":"refused"}', new \DateTimeImmutable()));
            $t->addToolCall(new AgentTurnToolCall(1, 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Rotule', 'rationale' => 'x'], '{"status":"shown"}', new \DateTimeImmutable()));
            $t->addToolCall(new AgentTurnToolCall(2, 'show_parts', ['categoryId' => 2, 'categoryLabel' => 'Cardan', 'rationale' => 'x'], json_encode(new ToolError('refused', 'At most 4 categories per turn: end the turn now.'), \JSON_THROW_ON_ERROR), new \DateTimeImmutable()));
        });

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('Rotule', $listed[0]['categoryLabel']);
    }

    public function testALegacyDocumentWithoutTheFieldStillHydrates(): void
    {
        $client = static::createClient();
        $turn = $this->seed('filtre', TurnSurface::Finder);
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->getDocumentCollection(AgentTurn::class)->updateOne(['_id' => (string) $turn->getId()], ['$unset' => ['categoryLabel' => '']]);
        $dm->clear();

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        self::assertResponseIsSuccessful();
        $this->assertSame('filtre', $listed[0]['instruction']);
        $this->assertArrayNotHasKey('categoryLabel', $listed[0]);
    }

    public function testANarrowedSearchInAProductGroupRoutesToItAndTakesItsName(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100198/narrowed?carType=PC&make=MAZDA&productGroupId=51', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'url', 'Search-Input' => 'rotule', 'Search-Plate' => 'XD-303-ZD'],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertSame(51, $this->rows()[0]->getSearch()['productGroupId']);
        $this->assertSame('Rotule de direction intérieure, barre de connexion', $this->rows()[0]->getCategoryLabel());
        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('p/XD-303-ZD/c/100198/g/51', $listed[0]['route']);
    }

    public function testAMixedCategoryWithoutAGroupIsJournaledAsSuch(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100198/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'url', 'Search-Input' => 'rotule'],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertSame('group_required', $this->rows()[0]->getSearch()['outcome']);
        $this->assertArrayNotHasKey('productGroupId', $this->rows()[0]->getSearch());
    }

    public function testANarrowedSearchCarryingThePagePlateRoutesToThePlate(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre', 'Search-Plate' => rawurlencode(' XB-301-ZB ')],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertSame('XB-301-ZB', $this->rows()[0]->getSearch()['plate']);
        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('p/XB-301-ZB/c/100259', $listed[0]['route']);
        $this->assertSame('XB-301-ZB', $listed[0]['vehicle']['plate']);
    }

    public function testAnOverlongPlateHeaderIsDropped(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre', 'Search-Plate' => str_repeat('A', 21)],
        ]);

        $this->assertArrayNotHasKey('plate', $this->rows()[0]->getSearch());
    }

    public function testAPlateLookupRowRoutesToThePlate(): void
    {
        $client = static::createClient();

        $client->request('GET', '/vehicles/XC302ZC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'plate', 'Search-Input' => 'XC-302-ZC']]);
        self::assertResponseIsSuccessful();

        $search = $this->rows()[0]->getSearch();
        $this->assertSame(['XC302ZC', 34247, 'CV', 'IVECO', 'DAILY'], [$search['plate'], $search['carId'], $search['carType'], $search['make'], $search['model']]);

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame('p/XC302ZC', $listed[0]['route']);
        $this->assertSame(['plate' => 'XC302ZC', 'carId' => 34247, 'carType' => 'CV', 'make' => 'IVECO', 'model' => 'DAILY', 'engine' => 'CLASSE C · F1AE0481B'], $listed[0]['vehicle']);
    }

    public function testAnAgentRowRoutesToTheVehicleAndCategoryItShowed(): void
    {
        $client = static::createClient();
        $this->seed('rotule axiale 307 XB-301-ZB', TurnSurface::Finder, static function (AgentTurn $t): void {
            $t->setVehicle(['carId' => 19107, 'carType' => 'PC', 'plate' => null, 'make' => 'MAZDA', 'model' => '6']);
            $t->addToolCall(new AgentTurnToolCall(0, 'set_vehicle', ['carId' => 9586, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'engine' => '1.6 HDi', 'plate' => 'XB-301-ZB'], 'ok', new \DateTimeImmutable()));
            $t->addToolCall(new AgentTurnToolCall(1, 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Rotule', 'rationale' => 'x'], '{}', new \DateTimeImmutable()));
        });
        $this->seed('filtre à huile', TurnSurface::Finder, static fn (AgentTurn $t) => $t->setVehicle(['carId' => 19107, 'carType' => 'PC', 'plate' => null, 'make' => 'MAZDA', 'model' => '6']));

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $byInstruction = array_column($listed, null, 'instruction');
        $this->assertSame('p/XB-301-ZB/c/100259', $byInstruction['rotule axiale 307 XB-301-ZB']['route']);
        $this->assertSame(['plate' => 'XB-301-ZB', 'carId' => 9586, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'engine' => '1.6 HDi'], $byInstruction['rotule axiale 307 XB-301-ZB']['vehicle']);
        $this->assertSame('k/19107-PC', $byInstruction['filtre à huile']['route']);
    }

    public function testAPlateLookupIsJournaledWithItsOutcome(): void
    {
        $client = static::createClient();

        $client->request('GET', '/vehicles/ZZ999ZZ', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'plate', 'Search-Input' => 'zz 999 zz']]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(AgentTurnStatus::Failed, $rows[0]->getStatus());
        $this->assertSame('plate', $rows[0]->getSearch()['rule']);
        $this->assertSame('not_found', $rows[0]->getSearch()['outcome']);
        $this->assertSame(404, $rows[0]->getSearch()['httpStatus']);
    }

    public function testARequestWithoutTheTypedInputIsNotJournaled(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'url', 'Search-Id' => 'abc'],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/vehicles/XC302ZC', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'url']]);
        self::assertResponseIsSuccessful();

        $this->assertSame([], $this->rows());
    }

    public function testTheRequestsOfOneSearchShareOneRow(): void
    {
        $client = static::createClient();
        $headers = self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'plate', 'Search-Input' => 'XC-302-ZC', 'Search-Id' => '6f1c0a52-1d0e-4a54-9a07-2d4f3c2a9e11'];

        $client->request('GET', '/vehicles/XC302ZC', ['headers' => $headers]);
        self::assertResponseIsSuccessful();
        $narrowed = $client->request('GET', '/cars/34247/part-categories/100259/narrowed?carType=CV', ['headers' => $headers])->toArray();
        self::assertResponseIsSuccessful();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('XC-302-ZC', $rows[0]->getInstruction());
        $search = $rows[0]->getSearch();
        $this->assertSame(['XC302ZC', 34247, 'IVECO', 'DAILY', 100259, 'plate'], [$search['plate'], $search['carId'], $search['make'], $search['model'], $search['categoryId'], $search['rule']]);
        $this->assertSame(\count($narrowed['clusters']), $search['clusterCount']);
    }

    public function testTheVehicleHeaderFillsMakeModelAndEngineWithoutAPlateLookup(): void
    {
        $client = static::createClient();
        $vehicle = rawurlencode(json_encode(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 MZR · LF'], \JSON_THROW_ON_ERROR));

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre à huile', 'Search-Vehicle' => $vehicle],
        ]);
        self::assertResponseIsSuccessful();

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        $this->assertSame(['plate' => null, 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 MZR · LF'], $listed[0]['vehicle']);
    }

    public function testAMalformedVehicleHeaderIsIgnored(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre', 'Search-Vehicle' => '%7Bnot-json'],
        ]);
        self::assertResponseIsSuccessful();

        $this->assertCount(1, $this->rows());
        $this->assertArrayNotHasKey('make', $this->rows()[0]->getSearch());
    }

    public function testAnonymousSearchesAreRefusedAndNotJournaled(): void
    {
        $client = static::createAnonymousClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => ['Accept' => 'application/ld+json', 'Search-Rule' => 'category', 'Search-Input' => 'filtre à huile'],
        ]);
        self::assertResponseStatusCodeSame(401);

        $this->assertSame([], $this->rows());
    }

    public function testSearchesFromOtherClientsAreNotJournaled(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();

        $this->assertSame([], $this->rows());
    }

    /**
     * @return list<AgentTurn>
     */
    private function rows(): array
    {
        static::getContainer()->get(DocumentManager::class)->clear();

        return static::getContainer()->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
    }

    /**
     * @param (\Closure(AgentTurn): void)|null $with
     */
    private function seed(string $instruction, TurnSurface $surface, ?\Closure $with = null, string $model = 'claude-sonnet-5-5'): AgentTurn
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn(self::TEST_OWNER, 'sess', $instruction, 'ctx', 'anthropic', $model, true);
        $turn->setSurface($surface);
        if (null !== $with) {
            $with($turn);
        }
        $turn->finish(AgentTurnStatus::Completed, null);
        $dm->persist($turn);
        $dm->flush();

        return $turn;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $data): array
    {
        return $data['member'] ?? $data['hydra:member'] ?? [];
    }
}
