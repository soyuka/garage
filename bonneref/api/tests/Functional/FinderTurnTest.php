<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Finder\PartPhotoHints;
use App\Agent\TurnSurface;
use App\Agent\WebSearch\WebSearchClient;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Entity\AgentTurnToolCall;
use App\Entity\FinderSelection;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\PhotoIntake\IntakeReadings;
use App\PhotoIntake\PhotoIntakeResult;
use App\PhotoIntake\PhotoKind;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\RecordingWebSearchClient;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

final class FinderTurnTest extends AppTestCase
{
    private const VEHICLE = ['carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 MZR'];

    private const PEUGEOT = ['carId' => 16182, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'engine' => '1.6 16V', 'plate' => 'XD-303-ZD'];

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x9a\xa0\xa0\x00\x00\x00\x00IEND\xaeB`\x82";

    public function testFinderTurnRunsTheFinderAgentAndStreamsTheNarrowedParts(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['tools' => [], 'system' => '', 'context' => ''];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('f1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Mon libellé', 'rationale' => 'Le filtre à huile se change à chaque vidange.']),
            new ToolCall('f2', 'end_turn', []),
        ], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-finder', 'surface' => 'finder', 'vehicle' => self::VEHICLE],
        ]);

        $this->assertResponseStatusCodeSame(202);

        self::assertStringContainsString('finder', $seen['system']);
        self::assertStringNotContainsString('Assistant Atelier', $seen['system']);
        self::assertStringContainsString('<follow_up>', $seen['system']);
        self::assertStringContainsString('<build_code>', $seen['system']);
        self::assertStringContainsString('decode_build_code', $seen['system']);
        self::assertStringContainsString('<product_groups>', $seen['system']);
        self::assertStringContainsString('<self_check>', $seen['system']);
        self::assertStringContainsString('<scope>', $seen['system']);
        self::assertStringContainsString('SEARCH_NOT_ALLOWED', $seen['system']);
        self::assertStringContainsString('Rotule de direction intérieure, barre de connexion', $seen['system']);
        self::assertStringContainsString('19107', $seen['context']);
        self::assertStringContainsString('MAZDA', $seen['context']);
        self::assertStringContainsString('filtre à huile', $seen['context']);

        foreach (['show_parts', 'show_web_part', 'ask_question', 'end_turn', 'web_search', 'lookup_plate', 'decode_vin', 'get_service_parts', 'decode_build_code'] as $tool) {
            self::assertContains($tool, $seen['tools'], "the finder agent must see $tool");
        }
        foreach (['log_maintenance', 'plan_maintenance', 'set_vehicle_facts', 'list_vehicles', 'toast', 'select_vehicle', 'propose_actions', 'narrate_step', 'narrow_parts', 'list_articles'] as $tool) {
            self::assertNotContains($tool, $seen['tools'], "the finder agent must not see $tool");
        }

        $frames = self::frames('sess-finder');
        self::assertSame(['type' => 'turn', 'phase' => 'start'], $frames[0]);
        self::assertSame(['type' => 'turn', 'phase' => 'end'], $frames[array_key_last($frames)]);
        self::assertSame([], array_values(array_filter($frames, static fn (array $f): bool => 'toast' === $f['type'])), 'a productive finder turn raises no failure toast');

        $shown = array_values(array_filter($frames, static fn (array $f): bool => 'showParts' === $f['type']));
        self::assertCount(1, $shown);
        self::assertSame(100259, $shown[0]['categoryId']);
        self::assertSame('Filtre à huile', $shown[0]['categoryLabel'], 'the title is the product group name, not the agent\'s categoryLabel');
        self::assertSame('Le filtre à huile se change à chaque vidange.', $shown[0]['rationale']);

        $route = $client->request('GET', '/cars/19107/part-categories/100259/narrowed?make=MAZDA')->toArray();
        $narrowed = $shown[0]['narrowed'];
        foreach (['carId', 'categoryId', 'clusters', 'totalArticles', 'totalBrands', 'unclassifiedCount', 'differsOn', 'narrowed', 'degradedReason', 'fallbackBrands', 'applicabilityScope', 'productGroup', 'productGroups', 'groupRequired'] as $key) {
            self::assertSame($route[$key] ?? null, $narrowed[$key] ?? null, "showParts.narrowed.$key must match GET /narrowed");
        }

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(['carId' => 19107, 'carType' => 'PC', 'plate' => null, 'make' => 'MAZDA', 'model' => '6 Hatchback (GG)'], $turns[0]->getVehicle(), 'the names are the catalogue\'s, not the payload\'s');
        self::assertNull($turns[0]->getPartsApiCalls(), 'no partsapi call is made since the catalogue is local');
    }

    public function testAskQuestionPublishesTheQuestionAndEndsTheTurn(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = ['calls' => 0];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('q1', 'ask_question', [
                'question' => 'Les freins avant ou arrière ?',
                'options' => [['label' => 'Avant', 'value' => 'avant'], ['label' => 'Arrière', 'value' => 'arriere']],
                'context' => 'freins qui grincent : essieu ?',
            ]),
            new ToolCall('q2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('mes freins grincent', 'sess-ask');

        self::assertSame(1, $seen['calls'], 'ask_question is terminal: no second model call');
        $asked = array_values(array_filter(self::frames('sess-ask'), static fn (array $f): bool => 'askQuestion' === $f['type']));
        self::assertSame([[
            'type' => 'askQuestion',
            'question' => 'Les freins avant ou arrière ?',
            'options' => [['label' => 'Avant', 'value' => 'avant'], ['label' => 'Arrière', 'value' => 'arriere']],
            'context' => 'freins qui grincent : essieu ?',
        ]], $asked);
    }

    public function testAskQuestionOffersUpToSixOptionsAndRejectsSeven(): void
    {
        static::createClient();
        $container = static::getContainer();

        $option = static fn (int $i): array => ['label' => "Version $i", 'value' => "car$i"];
        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('q1', 'ask_question', ['question' => 'Quelle version ?', 'options' => array_map($option, range(1, 7)), 'context' => 'mazda 6 2.0 2005 : 7 versions']),
            new ToolCall('q2', 'ask_question', ['question' => 'Quelle version ?', 'options' => array_map($option, range(1, 6)), 'context' => 'mazda 6 2.0 2005 : candidats 1 2 3 4 5 6']),
            new ToolCall('q3', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile mazda 6 2.0 2005', 'sess-six', vehicle: null);

        self::assertSame(6, $seen['schemas']['ask_question']['properties']['options']['maxItems'] ?? null, 'the advertised schema caps options at 6');
        $asked = array_values(array_filter(self::frames('sess-six'), static fn (array $f): bool => 'askQuestion' === $f['type']));
        self::assertCount(1, $asked, 'seven options are refused, six are published');
        self::assertCount(6, $asked[0]['options']);
    }

    public function testTheCandidateCarIdsOfAQuestionContextReachTheNextTurn(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = ['context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('e1', 'end_turn', [])], $seen));

        self::runFinderTurn('Essence', 'sess-candidates', vehicle: null, pendingContext: 'mazda 6 2.0 2005 : carburant ? candidats carIds 19107,19108,19109,19110,19111,19112,19113,19114,19115');

        self::assertStringContainsString('candidats carIds 19107,19108,19109,19110,19111,19112,19113,19114,19115', $seen['context']);
    }

    public function testShowWebPartPublishesAWebSourcedFind(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set(WebSearchClient::class, RecordingWebSearchClient::citingContitech());
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('w0', 'show_parts', ['categoryId' => 999999, 'rationale' => 'Demandé.']),
            new ToolCall('w00', 'web_search', ['query' => 'MAZDA 6 courroie de distribution']),
            new ToolCall('w1', 'show_web_part', [
                'categoryLabel' => 'Courroie de distribution',
                'reference' => 'CT1088',
                'brand' => 'CONTITECH',
                'sourceUrl' => 'https://example.org/ct1088',
                'sourceTitle' => 'ContiTech CT1088',
                'rationale' => 'Référence citée pour ce moteur, absente du catalogue.',
            ]),
            new ToolCall('w2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('courroie de distribution', 'sess-web');

        $web = array_values(array_filter(self::frames('sess-web'), static fn (array $f): bool => 'showWebPart' === $f['type']));
        self::assertSame([[
            'type' => 'showWebPart',
            'categoryLabel' => 'Courroie de distribution',
            'reference' => 'CT1088',
            'brand' => 'CONTITECH',
            'sourceUrl' => 'https://example.org/ct1088',
            'sourceTitle' => 'ContiTech CT1088',
            'rationale' => 'Référence citée pour ce moteur, absente du catalogue.',
        ]], $web);
    }

    public function testPendingContextReachesTheFinderContext(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = ['context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('e1', 'end_turn', [])], $seen));

        self::runFinderTurn('Avant', 'sess-pending', pendingContext: 'freins qui grincent : essieu ?');

        self::assertStringContainsString('freins qui grincent : essieu ?', $seen['context']);
        self::assertStringContainsString('Avant', $seen['context']);
    }

    public function testAFollowUpCarriesTheQuestionAndTheBuildLabelReadOffThePhoto(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('b1', 'end_turn', [])], $seen));
        $previous = new AgentTurn(self::TEST_OWNER, 'sess', 'rotule axiale', 'ctx', 'anthropic', 'claude-haiku-5-5', true);
        $previous->addToolCall(new AgentTurnToolCall(0, 'ask_question', ['question' => 'Code de fabrication ?', 'options' => [], 'context' => 'rotule axiale : deux montages selon le n° d\'organisation'], 'ok', new \DateTimeImmutable()));
        $previous->finish(AgentTurnStatus::Completed, null);
        $container->get(DocumentManager::class)->persist($previous);
        $container->get(DocumentManager::class)->flush();
        $intakeId = $container->get(IntakeReadings::class)->keep(self::TEST_OWNER, new PhotoIntakeResult(PhotoKind::BuildLabel, buildLabel: BuildLabelHints::fromPayload(['opr' => '10512', 'printedCodes' => ['DAM 10512', "\u{0}"]])));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => [
                'instruction' => 'Code de fabrication : 10512',
                'sessionId' => 'sess-buildcode',
                'surface' => 'finder',
                'vehicle' => ['carId' => 19107, 'carType' => 'PC', 'plate' => 'XB-301-ZB'],
                'previousTurnId' => (string) $previous->getId(),
                'intakeId' => $intakeId,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertStringContainsString('rotule axiale : deux montages selon le n° d\'organisation', $seen['context']);
        self::assertStringContainsString('OPR 10512', $seen['context']);
        self::assertStringContainsString('set_build_code', $seen['context']);
        self::assertStringContainsString('plaque XB-301-ZB', $seen['context']);
    }

    public function testAProseOnlyFinderTurnTellsTheUserInFinderTerms(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([], $seen));

        self::runFinderTurn('filtre à huile', 'sess-noop');

        $toasts = array_values(array_filter(self::frames('sess-noop'), static fn (array $f): bool => 'toast' === $f['type']));
        self::assertCount(1, $toasts);
        self::assertSame('Aucune pièce trouvée. Reformulez la demande ou choisissez une catégorie.', $toasts[0]['message']);
    }

    public function testSetVehicleHandsTheCarToThePageAndUnlocksShowParts(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('v1', 'set_vehicle', ['carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 MZR']),
            new ToolCall('v2', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Demandé.']),
            new ToolCall('v3', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile mazda 6 2.0', 'sess-setvehicle', vehicle: null);

        $frames = self::frames('sess-setvehicle');
        $set = array_values(array_filter($frames, static fn (array $f): bool => 'setVehicle' === $f['type']));
        self::assertSame([['type' => 'setVehicle', 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6 Hatchback (GG)', 'engine' => '2.0', 'plate' => null]], $set, 'the catalogue names the car, not the agent');
        self::assertCount(1, array_filter($frames, static fn (array $f): bool => 'showParts' === $f['type']));
    }

    public function testShowPartsSummaryCarriesPerArticleApplicabilityWhenClustersDiffer(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('a1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Demandé.']),
            new ToolCall('a2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile', 'sess-applicability', vehicle: ['carId' => 19107, 'carType' => 'PC']);

        $summary = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertIsArray($summary);
        self::assertGreaterThan(1, \count($summary['clusters']));
        self::assertStringContainsString('per variant', $summary['applicabilityCaveat']);
        $applicability = implode("\n", array_column($summary['clusters'], 'applicability'));
        self::assertStringContainsString('Nº de châssis à partir de : 176451', $applicability);
        self::assertStringContainsString('Code moteur : LF', $applicability);
    }

    public function testShowPartsSummaryOmitsApplicabilityForASingleCluster(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('s1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 914, 'rationale' => 'Demandé.']),
            new ToolCall('s2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile', 'sess-single');

        $summary = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertCount(1, $summary['clusters']);
        self::assertArrayNotHasKey('applicabilityCaveat', $summary);
        self::assertArrayNotHasKey('applicability', $summary['clusters'][0]);
    }

    public function testShowPartsOnAMixedCategoryReturnsItsProductGroupsAndDrawsNothing(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('g1', 'show_parts', ['categoryId' => 100198, 'rationale' => 'Demandé.']),
            new ToolCall('g2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('rotule axiale', 'sess-groups', vehicle: self::VEHICLE);

        $result = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('choose_group', $result['status'] ?? null);
        self::assertSame(['Rotule de direction intérieure, barre de connexion', 'Rotule de barre de connexion', 'Rotule de direction'], array_column($result['productGroups'], 'name'));
        self::assertSame([51, 914, 2066], array_column($result['productGroups'], 'id'));
        self::assertSame([2, 1, 1], array_column($result['productGroups'], 'articleCount'));
        self::assertSame([], array_values(array_filter(self::frames('sess-groups'), static fn (array $f): bool => 'showParts' === $f['type'])));
    }

    public function testTheShownLabelIsTheProductGroupNameNeverTheAgentsText(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('l1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'categoryLabel' => 'Rotule axiale', 'rationale' => 'Demandé.']),
            new ToolCall('l2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('rotule axiale', 'sess-label', vehicle: self::VEHICLE);

        $shown = array_values(array_filter(self::frames('sess-label'), static fn (array $f): bool => 'showParts' === $f['type']));
        self::assertCount(1, $shown);
        self::assertSame('Rotule de direction intérieure, barre de connexion', $shown[0]['categoryLabel']);
        self::assertSame(51, $shown[0]['narrowed']['productGroup']['id']);

        $summary = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('shown', $summary['status']);
        self::assertSame(['id' => 51, 'name' => 'Rotule de direction intérieure, barre de connexion'], $summary['productGroup']);

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertSame('Rotule de direction intérieure, barre de connexion', $turns[0]->getCategoryLabel());
    }

    public function testEachShownClusterNamesItsProductGroup(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('c1', 'show_parts', ['categoryId' => 100259, 'rationale' => 'Demandé.']),
            new ToolCall('c2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile', 'sess-cluster-group');

        $summary = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('Filtre à huile', $summary['clusters'][0]['productGroup']);
    }

    public function testShowPartsSelectsOneClusterAndTheFrameCarriesItsBasis(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('k1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'clusterId' => 'c1', 'rationale' => 'code de fabrication (OPR 09824 ≤ 10310)']),
            new ToolCall('k2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-select', vehicle: self::VEHICLE);

        $shown = array_values(array_filter(self::frames('sess-select'), static fn (array $f): bool => 'showParts' === $f['type']));
        self::assertCount(1, $shown);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $shown[0]['selection']['id'] ?? '');
        self::assertSame(['clusterId' => 'c1', 'basis' => 'code de fabrication (OPR 09824 ≤ 10310)'], array_diff_key($shown[0]['selection'], ['id' => true]));

        $summary = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('shown', $summary['status']);
        self::assertSame('c1', $summary['selected']);
        self::assertSame($shown[0]['selection']['id'], $summary['selectionId']);
        self::assertArrayHasKey('clusterId', $seen['schemas']['show_parts']['properties'] ?? []);
    }

    public function testASelectionIsStoredAndReadBackByItsIdWithoutOwnerData(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('s1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'clusterId' => 'c1', 'rationale' => 'code de fabrication (OPR 09824 ≤ 10310)', 'setAside' => 'OPR ≥ 10311']),
            new ToolCall('s2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-stored', vehicle: self::VEHICLE + ['buildCode' => ['organisationNumber' => '09824', 'date' => '2003-10-02', 'codeKind' => 'dam']]);

        $shown = array_values(array_filter(self::frames('sess-stored'), static fn (array $f): bool => 'showParts' === $f['type']));
        $id = $shown[0]['selection']['id'];

        $stored = $container->get(DocumentManager::class)->find(FinderSelection::class, $id);
        self::assertNotNull($stored);
        self::assertSame(self::TEST_OWNER, $stored->owner);
        self::assertSame('09824', $stored->organisationNumber);
        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 1);
        self::assertSame((string) $turns[0]->getId(), $stored->turnId);

        $read = $client->request('GET', '/agent/selections/'.$id, ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseIsSuccessful();
        self::assertSame([
            'id' => $id,
            'carId' => 19107,
            'carType' => 'PC',
            'categoryId' => 100198,
            'productGroupId' => 51,
            'clusterId' => 'c1',
            'basis' => 'code de fabrication (OPR 09824 ≤ 10310)',
            'setAside' => 'OPR ≥ 10311',
        ], $read->toArray());
    }

    public function testAnUnknownOrMalformedSelectionIdIsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/agent/selections/01JA0000000000000000000000', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/agent/selections/code%20de%20fabrication', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testShowPartsCarriesWhyTheOtherClustersWereSetAside(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('a1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'clusterId' => 'c1', 'rationale' => 'code de fabrication (OPR 09824 ≤ 10310)', 'setAside' => ' OPR ≥ 10311 ']),
            new ToolCall('a2', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'rationale' => 'Demandé.', 'setAside' => 'ignoré sans clusterId']),
            new ToolCall('a3', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-setaside', vehicle: self::VEHICLE);

        $shown = array_values(array_filter(self::frames('sess-setaside'), static fn (array $f): bool => 'showParts' === $f['type']));
        self::assertSame(['clusterId' => 'c1', 'basis' => 'code de fabrication (OPR 09824 ≤ 10310)', 'setAside' => 'OPR ≥ 10311'], array_diff_key($shown[0]['selection'], ['id' => true]));
        self::assertArrayNotHasKey('selection', $shown[1]);
        self::assertArrayHasKey('setAside', $seen['schemas']['show_parts']['properties'] ?? []);
    }

    public function testSetBuildCodePinsTheDecodedCodeOnThePage(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('b1', 'set_build_code', ['code' => '982488']),
            new ToolCall('b2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-buildcode', vehicle: self::PEUGEOT);

        $set = array_values(array_filter(self::frames('sess-buildcode'), static fn (array $f): bool => 'setBuildCode' === $f['type']));
        self::assertSame([['type' => 'setBuildCode', 'organisationNumber' => '09824', 'date' => '2003-10-02', 'codeKind' => 'dam']], $set);
        $result = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame(['decoded', 'PEUGEOT', '09824'], [$result['status'], $result['make'], $result['organisationNumber']]);
    }

    public function testSetBuildCodeForAnUnsupportedMakePublishesNothing(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('u1', 'set_build_code', ['code' => '982488']),
            new ToolCall('u2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-buildcode-mazda', vehicle: self::VEHICLE);

        self::assertSame([], array_values(array_filter(self::frames('sess-buildcode-mazda'), static fn (array $f): bool => 'setBuildCode' === $f['type'])));
        self::assertSame('unsupported_make', json_decode($seen['toolResults'][0] ?? '', true)['status']);
    }

    public function testAnUnknownClusterIdIsRefusedAndNothingIsDrawn(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('u1', 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'clusterId' => 'c9', 'rationale' => 'code de fabrication (OPR 09824 ≤ 10310)']),
            new ToolCall('u2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-unknown-cluster', vehicle: self::VEHICLE);

        $result = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertTrue($result['isError'] ?? null);
        [$reason, $details] = explode("\n", $result['content'][0]['text'], 2);
        self::assertStringStartsWith('refused: clusterId c9', $reason);
        self::assertSame(['clusterIds' => ['c1', 'c2']], json_decode($details, true));
        self::assertSame([], array_values(array_filter(self::frames('sess-unknown-cluster'), static fn (array $f): bool => 'showParts' === $f['type'])));
    }

    public function testAClusterIdWithoutItsProductGroupTellsTheAgentToReuseTheOnScreenGroup(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('n1', 'show_parts', ['categoryId' => 100198, 'clusterId' => 'c1', 'rationale' => 'code de fabrication (OPR 09824 ≤ 10310)']),
            new ToolCall('n2', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('Code de fabrication : 982488', 'sess-select-nogroup', vehicle: self::VEHICLE);

        $result = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('choose_group', $result['status'] ?? null);
        self::assertStringContainsString('productGroupId of the result on screen', $result['reason'] ?? '');
    }

    public function testRedrawingTheSameCategoryDoesNotSpendTheCategoryCap(): void
    {
        static::createClient();
        $container = static::getContainer();

        $call = static fn (string $id, ?string $cluster): ToolCall => new ToolCall($id, 'show_parts', ['categoryId' => 100198, 'productGroupId' => 51, 'clusterId' => $cluster, 'rationale' => 'Rotule axiale.']);
        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            $call('r1', null), $call('r2', 'c1'), $call('r3', 'c2'), $call('r4', null), $call('r5', 'c1'),
            new ToolCall('r6', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('rotule axiale', 'sess-redraw', vehicle: self::VEHICLE);

        self::assertSame(['shown'], array_values(array_unique(array_map(static fn (string $r): string => json_decode($r, true)['status'] ?? '', \array_slice($seen['toolResults'], 0, 5)))));
    }

    public function testShowPartsWithoutAVehicleTellsTheAgentToSetOneFirst(): void
    {
        static::createClient();
        $container = static::getContainer();

        $results = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('p1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Demandé.']),
            new ToolCall('p2', 'end_turn', []),
        ], $results));

        self::runFinderTurn('filtre à huile', 'sess-noset', vehicle: null);

        self::assertSame([], array_values(array_filter(self::frames('sess-noset'), static fn (array $f): bool => 'showParts' === $f['type'])));
        self::assertStringContainsString('set_vehicle', $results['toolResults'][0] ?? '');
    }

    public function testAnEmptyCategoryIsNeverDrawnAndTellsTheAgentToTryASibling(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('e1', 'show_parts', ['categoryId' => 999999, 'categoryLabel' => 'Filtre vide', 'rationale' => 'Demandé.']),
            new ToolCall('e2', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Demandé.']),
            new ToolCall('e3', 'end_turn', []),
        ], $seen));

        self::runFinderTurn('filtre à huile', 'sess-empty');

        $empty = json_decode($seen['toolResults'][0] ?? '', true);
        self::assertSame('empty', $empty['status'] ?? null);
        self::assertStringContainsString('sibling', $empty['reason'] ?? '');
        $shown = array_values(array_filter(self::frames('sess-empty'), static fn (array $f): bool => 'showParts' === $f['type']));
        self::assertSame([100259], array_column($shown, 'categoryId'));
    }

    public function testImagesReachTheModelAndOnlyTheirShapeIsJournaled(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('i1', 'end_turn', [])], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => [
                'instruction' => 'quelle est cette pièce ?',
                'sessionId' => 'sess-photo',
                'surface' => 'finder',
                'vehicle' => self::VEHICLE,
                'intent' => 'photo',
                'intakeId' => $container->get(IntakeReadings::class)->keep(self::TEST_OWNER, new PhotoIntakeResult(PhotoKind::Part, part: PartPhotoHints::fromPayload(['familyGuess' => 'filtre à huile', 'printedReferences' => ['W 712/73'], 'brandGuess' => 'MANN-FILTER']))),
                'images' => ['data:image/png;base64,'.base64_encode(self::PNG), 'data:image/png;base64,'.base64_encode(self::PNG)],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertSame(['image/png', 'image/png'], $seen['images']);
        self::assertStringContainsString('W 712/73', $seen['context']);
        self::assertStringContainsString('MANN-FILTER', $seen['context']);

        $turn = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 1)[0];
        self::assertSame([['mimeType' => 'image/png', 'bytes' => \strlen(self::PNG)], ['mimeType' => 'image/png', 'bytes' => \strlen(self::PNG)]], $turn->getImages());
        self::assertStringNotContainsString(base64_encode(self::PNG), serialize([$turn->getContext(), $turn->getRawCalls(), $turn->getInstruction()]));
    }

    public function testAReferenceIntentIsSpelledOutForTheAgent(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('r1', 'end_turn', [])], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'W 712/73', 'sessionId' => 'sess-ref', 'surface' => 'finder', 'intent' => 'reference'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertStringContainsString('référence', $seen['context']);
        self::assertStringContainsString('W 712/73', $seen['context']);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int}>
     */
    public static function badImages(): iterable
    {
        $png = 'data:image/png;base64,'.base64_encode(self::PNG);
        yield 'more than four' => [[$png, $png, $png, $png, $png], 400];
        yield 'one image over four megabytes' => [['data:image/png;base64,'.base64_encode(self::PNG.str_repeat("\0", 4 * 1024 * 1024))], 413];
        yield 'not a data url' => [['https://example.org/a.png'], 400];
        yield 'a gif labelled png' => [['data:image/png;base64,'.base64_encode('GIF89a'.str_repeat("\0", 32))], 415];
        yield 'over ten megabytes' => [['data:image/png;base64,'.base64_encode(self::PNG.str_repeat("\0", 10 * 1024 * 1024))], 413];
    }

    /**
     * @param list<string> $images
     */
    #[DataProvider('badImages')]
    public function testImagesAreValidatedBeforeAnyModelCall(array $images, int $status): void
    {
        ini_set('memory_limit', '1G');
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['calls' => 0];
        $container->set('ai.platform.anthropic', self::script([], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'x', 'sessionId' => 'sess-bad', 'surface' => 'finder', 'images' => $images],
        ]);

        $this->assertResponseStatusCodeSame($status);
        self::assertSame(0, $seen['calls']);
    }

    public function testFourImagesAreAccepted(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $seen = [];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('i1', 'end_turn', [])], $seen));
        $png = 'data:image/png;base64,'.base64_encode(self::PNG);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'quelle pièce ?', 'sessionId' => 'sess-four', 'surface' => 'finder', 'images' => [$png, $png, $png, $png]],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertCount(4, $seen['images']);
    }

    public function testAnInstructionOverFiveHundredCharactersIsRefusedBeforeAnyModelCall(): void
    {
        $client = static::createClient();
        $seen = ['calls' => 0];
        static::getContainer()->set('ai.platform.anthropic', self::script([], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => str_repeat('é', 501), 'sessionId' => 'sess-long', 'surface' => 'finder'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('500', $client->getResponse()->getContent(false));
        self::assertSame(0, $seen['calls']);
    }

    public function testAnInstructionOfFiveHundredCharactersIsAccepted(): void
    {
        $client = static::createClient();
        $seen = [];
        static::getContainer()->set('ai.platform.anthropic', self::script([new ToolCall('e1', 'end_turn', [])], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => str_repeat('é', 500), 'sessionId' => 'sess-500', 'surface' => 'finder'],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    public function testAMalformedSessionIdIsRefused(): void
    {
        $client = static::createClient();

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'x', 'sessionId' => 'a/b?topic='.str_repeat('x', 200), 'surface' => 'finder'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnknownSurfaceIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'x', 'sessionId' => 'sess-x', 'surface' => 'cockpit'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed>|null $vehicle
     */
    private static function runFinderTurn(string $instruction, string $session, string $pendingContext = '', ?array $vehicle = self::VEHICLE): void
    {
        static::getContainer()->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: $instruction,
            sessionTopic: 'https://localhost/agent/sessions/'.$session,
            owner: self::TEST_OWNER,
            pendingContext: $pendingContext,
            surface: TurnSurface::Finder,
            vehicle: null === $vehicle ? null : FinderVehicle::fromPayload($vehicle),
        ));
    }

    /**
     * @param list<ToolCall>       $steps one tool call per model round-trip, then a closing text
     * @param array<string, mixed> $seen
     */
    private static function script(array $steps, array &$seen): ScriptedStreamPlatform
    {
        return new ScriptedStreamPlatform(static function ($model, $input, array $options) use ($steps, &$seen): ToolCallResult|string {
            $seen['calls'] = ($seen['calls'] ?? 0) + 1;
            $seen['tools'] = array_map(static fn (Tool $t): string => $t->getName(), array_filter($options['tools'] ?? [], static fn ($t): bool => $t instanceof Tool));
            $seen['schemas'] = [];
            foreach ($options['tools'] ?? [] as $tool) {
                if ($tool instanceof Tool) {
                    $seen['schemas'][$tool->getName()] = $tool->getParameters();
                }
            }

            $executed = 0;
            $seen['toolResults'] = [];
            $messages = $input instanceof MessageBag ? $input->getMessages() : [];
            foreach ($messages as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                    $seen['toolResults'][] = (string) $message->asText();
                }
            }
            if ($input instanceof MessageBag) {
                $system = $input->getSystemMessage()?->getContent();
                $seen['system'] = $system instanceof Template ? $system->getTemplate() : (string) $system;
                $user = $input->getUserMessage();
                $seen['context'] = null === $user ? '' : implode("\n", array_map(static fn ($c): string => method_exists($c, 'getText') ? $c->getText() : '', $user->getContent()));
                $seen['images'] = null === $user ? [] : array_values(array_map(static fn (Image $i): string => $i->getFormat(), array_filter($user->getContent(), static fn ($c): bool => $c instanceof Image)));
            }

            return isset($steps[$executed]) ? new ToolCallResult([$steps[$executed]]) : 'Fait.';
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function frames(string $session): array
    {
        $updates = array_filter(
            static::getContainer()->get(RecordingHub::class)->updates,
            static fn ($u): bool => str_ends_with($u->getTopics()[0] ?? '', '/agent/sessions/'.$session),
        );

        return array_values(array_map(static fn ($u): array => json_decode($u->getData(), true), $updates));
    }
}
