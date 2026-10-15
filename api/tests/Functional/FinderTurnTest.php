<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderVehicle;
use App\Agent\TurnSurface;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Repository\AgentTurnRepository;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\ScriptedStreamPlatform;
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

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x9a\xa0\xa0\x00\x00\x00\x00IEND\xaeB`\x82";

    public function testFinderTurnRunsTheFinderAgentAndStreamsTheNarrowedParts(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['tools' => [], 'system' => '', 'context' => ''];
        $container->set('ai.platform.anthropic', self::script([
            new ToolCall('f1', 'show_parts', ['categoryId' => 100259, 'categoryLabel' => 'Filtre à huile', 'rationale' => 'Le filtre à huile se change à chaque vidange.']),
            new ToolCall('f2', 'end_turn', []),
        ], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-finder', 'surface' => 'finder', 'vehicle' => self::VEHICLE],
        ]);

        $this->assertResponseStatusCodeSame(202);

        self::assertStringContainsString('finder', $seen['system']);
        self::assertStringNotContainsString('Assistant Atelier', $seen['system']);
        self::assertStringContainsString('19107', $seen['context']);
        self::assertStringContainsString('MAZDA', $seen['context']);
        self::assertStringContainsString('filtre à huile', $seen['context']);

        foreach (['show_parts', 'show_web_part', 'ask_question', 'end_turn', 'web_search', 'lookup_plate', 'decode_vin', 'list_part_categories', 'get_service_parts'] as $tool) {
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
        self::assertSame('Filtre à huile', $shown[0]['categoryLabel']);
        self::assertSame('Le filtre à huile se change à chaque vidange.', $shown[0]['rationale']);

        $route = $client->request('GET', '/cars/19107/part-categories/100259/narrowed?make=MAZDA')->toArray();
        $narrowed = $shown[0]['narrowed'];
        foreach (['carId', 'categoryId', 'clusters', 'totalArticles', 'totalBrands', 'unclassifiedCount', 'differsOn', 'narrowed', 'degradedReason', 'fallbackBrands'] as $key) {
            self::assertSame($route[$key] ?? null, $narrowed[$key] ?? null, "showParts.narrowed.$key must match GET /narrowed");
        }

        $turns = $container->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
        self::assertCount(1, $turns);
        self::assertSame(TurnSurface::Finder, $turns[0]->getSurface());
        self::assertNotNull($turns[0]->getPartsApiCalls(), "the agent turn journals its catalogue spend");
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

    public function testShowWebPartPublishesAWebSourcedFind(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = [];
        $container->set('ai.platform.anthropic', self::script([
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

    public function testAFollowUpCarriesTheScreenTheQuestionAndTheBuildCode(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('b1', 'end_turn', [])], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => [
                'instruction' => 'voici la vignette, OPR 10512',
                'sessionId' => 'sess-buildcode',
                'surface' => 'finder',
                'vehicle' => ['carId' => 9586, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'plate' => 'XB-301-ZB'],
                'summary' => 'Rotule axiale (100123) — 2 groupes : TRW JAR123 / TRW JAR456',
                'pendingContext' => 'rotule axiale 307 : deux montages selon le n° d\'organisation',
                'buildLabel' => ['opr' => '10512', 'printedCodes' => ['DAM 10512', "\u{0}"]],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertStringContainsString('rotule axiale 307 : deux montages selon le n° d\'organisation', $seen['context']);
        self::assertStringContainsString('OPR 10512', $seen['context']);
        self::assertStringContainsString('decode_build_code', $seen['context']);
        self::assertStringContainsString('Rotule axiale (100123) — 2 groupes', $seen['context']);
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
        self::assertStringContainsString('pièce', $toasts[0]['message']);
        self::assertStringNotContainsString('enregistrer', $toasts[0]['message']);
    }

    public function testGarageAgentDoesNotSeeTheFinderTools(): void
    {
        static::createClient();
        $container = static::getContainer();

        $seen = ['tools' => []];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('g1', 'end_turn', [])], $seen));

        $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: 'Bonjour',
            sessionTopic: 'https://localhost/agent/sessions/sess-garage',
            owner: self::TEST_OWNER,
        ));

        self::assertContains('toast', $seen['tools']);
        foreach (['show_parts', 'show_web_part', 'ask_question'] as $tool) {
            self::assertNotContains($tool, $seen['tools']);
        }
    }

    public function testAMalformedVehicleIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-x', 'surface' => 'finder', 'vehicle' => ['make' => 'MAZDA']],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAFinderTurnWithoutVehicleStartsByIdentifyingIt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $seen = ['tools' => [], 'context' => ''];
        $container->set('ai.platform.anthropic', self::script([new ToolCall('n1', 'end_turn', [])], $seen));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile 307 1.4 hdi', 'sessionId' => 'sess-novehicle', 'surface' => 'finder'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        self::assertStringContainsString('Aucun véhicule identifié', $seen['context']);
        self::assertStringContainsString('filtre à huile 307 1.4 hdi', $seen['context']);
        foreach (['set_vehicle', 'lookup_plate', 'list_makes', 'list_models', 'list_cars'] as $tool) {
            self::assertContains($tool, $seen['tools'], "a vehicle-less finder turn needs $tool");
        }
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
        self::assertSame([['type' => 'setVehicle', 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6', 'engine' => '2.0 MZR', 'plate' => null]], $set);
        self::assertCount(1, array_filter($frames, static fn (array $f): bool => 'showParts' === $f['type']));
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
                'photoHints' => ['familyGuess' => 'filtre à huile', 'printedReferences' => ['W 712/73'], 'brandGuess' => 'MANN-FILTER'],
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
        yield 'more than three' => [[$png, $png, $png, $png], 400];
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
