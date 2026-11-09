<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\ActionButton;
use App\Agent\Ui\ActionPart;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\SpecNode;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\TestDashboardChannel;
use App\Tests\Mock\TestRenderTargets;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RenderIntentToolsTest extends TestCase
{
    private const string VEHICLE = '01KVEF1TCCBTWQR5CV6V05PQ80';
    private const string TASK = '01KVEF1TCCBTWQR5CV6V05PQ81';

    public function testPublishFailureIsBestEffortAndDoesNotBreakTheTurn(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('Failed to send an update.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $tools = new RenderIntentTools($hub, $logger, TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));

        $this->assertSame('ok', $tools->toast('Entretien enregistré', 'ok'));
    }

    public function testPublishSerialisesTheTypedIntentToTheBoundSessionTopic(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->selectVehicle('/garage/vehicles/'.self::VEHICLE);

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(['type' => 'selectVehicle', 'id' => '/garage/vehicles/'.self::VEHICLE], json_decode($published->getData(), true));
    }

    public function testBoundTurnPublishesOnlyItsLatestToastImmediatelyBeforeItEnds(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->signalTurnPhase('start');
        $tools->toast('Je regarde les tâches en cours.');
        $tools->focusView('tasks', ['coolant']);
        $tools->toast('La sonde est dans la maintenance en cours, il faut 7,5 l de liquide de refroidissement.', 'ok');
        $tools->signalTurnPhase('end');

        $this->assertSame([
            ['type' => 'turn', 'phase' => 'start'],
            ['type' => 'focusView', 'panel' => 'tasks', 'keys' => ['coolant']],
            ['type' => 'toast', 'tone' => 'ok', 'message' => 'La sonde est dans la maintenance en cours, il faut 7,5 l de liquide de refroidissement.'],
            ['type' => 'turn', 'phase' => 'end'],
        ], $published);
    }

    public function testUnboundPilotToastPublishesImmediately(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));

        $tools->toast('Entretien enregistré.', 'ok');

        $this->assertSame([
            ['type' => 'toast', 'tone' => 'ok', 'message' => 'Entretien enregistré.'],
        ], $published);
    }

    public function testProposeActionsDiscardsAPendingBoundTurnToast(): void
    {
        $published = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->signalTurnPhase('start');
        $tools->toast('Une première réponse.');
        $tools->proposeActions('Choisir une référence', [new ActionButton('Ajouter', 'ajoute cette référence')], 'La réponse est dans cette carte.');
        $tools->signalTurnPhase('end');

        $this->assertSame([
            ['type' => 'turn', 'phase' => 'start'],
            [
                'type' => 'proposeActions',
                'headline' => 'Choisir une référence',
                'actions' => [['label' => 'Ajouter', 'instruction' => 'ajoute cette référence']],
                'finding' => 'La réponse est dans cette carte.',
            ],
            ['type' => 'turn', 'phase' => 'end'],
        ], $published);
    }

    /**
     * The LLM-facing schema must describe each action as an OBJECT — a phpstan `array{...}` shape
     * silently degrades to "array of strings" and the model can never emit the right keys (see the
     * propose_actions tool-call failures). A button is EITHER an NL follow-up (`instruction`) OR a
     * deterministic write (`taskId` + typed `parts`); since the schema factory lists every ctor
     * param as required, that either/or rides on NULLABILITY (the model passes null for the unused
     * side) and is enforced at call time. `parts` must itself recurse into a typed object so the
     * model emits `{ref, brand, …}`, not bare strings.
     */
    public function testNarratePublishesATransientStatusFrameSeparateFromToast(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->narrate('Je consulte le catalogue de pièces…');

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        // A `status` frame, not a `toast`: progress narration feeds only the heartbeat line, never a
        // toast popup.
        $this->assertSame(
            ['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…'],
            json_decode($published->getData(), true),
        );
    }

    public function testNarrateStepPublishesAStatusFrameWithoutAToolKey(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('ok', $tools->narrateStep('Je relis le carnet…'));

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        // Model-authored narration has no tool identity: the dashboard's step chip keys on `tool`
        // and must not receive a bogus one; a tool step is the key alone, see StepNarrator.
        $this->assertSame(
            ['type' => 'status', 'message' => 'Je relis le carnet…'],
            json_decode($published->getData(), true),
        );
    }

    public function testProposeActionsSchemaDescribesEachActionAsTypedObject(): void
    {
        $schema = (new Factory())->buildParameters(RenderIntentTools::class, 'proposeActions');

        /** @var array<string, mixed> $actions */
        $actions = $schema['properties']['actions'];
        $items = $actions['items'];
        $this->assertSame('object', $items['type']);
        $this->assertSame('string', $items['properties']['label']['type']);
        // instruction/taskId are nullable, so the unused side can be passed as null.
        $this->assertContains('null', (array) $items['properties']['instruction']['type']);
        $this->assertContains('null', (array) $items['properties']['taskId']['type']);

        // parts is nullable too: a model filling the unused kind-(2) field sends null, not [].
        $this->assertContains('null', (array) $items['properties']['parts']['type']);
        $part = $items['properties']['parts']['items'];
        $this->assertSame('object', $part['type']);
        $this->assertSame('string', $part['properties']['ref']['type']);
    }

    /**
     * Builds the SAME {@see Toolbox} the bundle wires as `ai.toolbox.garage` (real
     * {@see \Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory}, real
     * {@see \Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver}) so a test drives the exact
     * hydration path production hit, instead of calling {@see RenderIntentTools::proposeActions()}
     * directly with already-typed {@see ActionButton} objects.
     */
    public function testAModelSuppliedHandleOutsideTheOwnersRecordsNeverReachesAFrame(): void
    {
        $hub = new RecordingHub();
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create('https://localhost/agent/sessions/abc'), new TestRenderTargets([self::VEHICLE], [self::TASK]));

        $this->assertStringStartsWith('invalid_input:', $tools->selectVehicle('https://evil.example/garage/vehicles/'.self::VEHICLE));
        $this->assertStringStartsWith('not_found:', $tools->selectVehicle('01KVEF1TCCBTWQR5CV6V05PQ99'));
        foreach (['https://evil.example/garage/planned-tasks/'.self::TASK, '/garage/vehicles/'.self::TASK, '/x/'.self::TASK, '01KVEF1TCCBTWQR5CV6V05PQ99'] as $handle) {
            $ack = $tools->proposeActions('Filtre', [new ActionButton('Ajouter', taskId: $handle, parts: [new ActionPart('W 712/73')])]);
            $this->assertMatchesRegularExpression('/^(invalid_input|not_found): /', $ack, $handle);
        }

        $this->assertSame([], $hub->updates);
    }

    private function realToolbox(RenderIntentTools $tools): Toolbox
    {
        return new Toolbox([$tools]);
    }

    /**
     * Reproduces the live 2026-09-05 failure verbatim: claude-sonnet-4-6 called `propose_actions`
     * with two NL-follow-up buttons, each carrying explicit `"taskId":null,"parts":null` for the
     * unused deterministic-write side. `ActionButton::$parts` was a non-nullable `array`, so the real
     * {@see \Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver} threw
     * `NotNormalizableValueException: Data expected to be "ActionPart[]", "null" given.` before
     * {@see RenderIntentTools::proposeActions()} ever ran — Toolbox::execute() then wrapped it as
     * `ToolExecutionException` ("Failed to execute tool ...").
     */
    public function testProposeActionsThroughTheRealToolboxAcceptsExplicitNullsForTheUnusedKind(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $result = $this->realToolbox($tools)->execute(new ToolCall('call_1', 'propose_actions', [
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange', 'taskId' => null, 'parts' => null],
                ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références', 'taskId' => null, 'parts' => null],
            ],
        ]));

        $this->assertSame('ok', $result->getResult());
        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange'],
                ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
            ],
        ], json_decode($published->getData(), true));
    }

    public function testProposeActionsThroughTheRealToolboxAcceptsExplicitNullInstructionForADeterministicWrite(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $result = $this->realToolbox($tools)->execute(new ToolCall('call_1', 'propose_actions', [
            'headline' => 'Référence filtre à huile',
            'actions' => [
                ['label' => 'Ajouter MANN W 712/73', 'instruction' => null, 'taskId' => '01KVEF1TCCBTWQR5CV6V05PQ81', 'parts' => [
                    ['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile'],
                ]],
            ],
        ]));

        $this->assertSame('ok', $result->getResult());
        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Référence filtre à huile',
            'actions' => [[
                'label' => 'Ajouter MANN W 712/73',
                'patchTask' => [
                    'id' => '/garage/planned-tasks/01KVEF1TCCBTWQR5CV6V05PQ81',
                    'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile']],
                ],
            ]],
        ], json_decode($published->getData(), true));
    }

    /**
     * A taskId with an EMPTY parts list is not a deterministic write (nothing to merge) — the
     * button falls back to its instruction. Existing behaviour, pinned through the real hydration
     * path so the nullability fix above cannot flip it.
     */
    public function testProposeActionsThroughTheRealToolboxFallsBackToInstructionWhenPartsIsEmpty(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $result = $this->realToolbox($tools)->execute(new ToolCall('call_1', 'propose_actions', [
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Voir le détail', 'instruction' => 'détaille la tâche', 'taskId' => '01KVEF1TCCBTWQR5CV6V05PQ81', 'parts' => []],
            ],
        ]));

        $this->assertSame('ok', $result->getResult());
        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Voir le détail', 'instruction' => 'détaille la tâche'],
            ],
        ], json_decode($published->getData(), true));
    }

    public function testShowFactSchemaDescribesEachProjectedNodeAsTypedObject(): void
    {
        $schema = (new Factory())->buildParameters(RenderIntentTools::class, 'showFact');

        /** @var array<string, mixed> $nodes */
        $nodes = $schema['properties']['nodes'];
        $node = $nodes['items'];
        $this->assertSame('object', $node['type']);
        foreach (['key', 'label', 'value'] as $field) {
            $this->assertSame('string', $node['properties'][$field]['type']);
        }
        $this->assertSame('boolean', $node['properties']['verified']['type']);
        $this->assertContains('null', (array) $node['properties']['info']['type']);
    }

    public function testProposeActionsPublishesAStructuredPatchTaskWrite(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        // A bare ULID is normalised to the task's tick-off IRI so the dashboard can PATCH it directly.
        $tools->proposeActions('Référence filtre à huile', [
            new ActionButton('Ajouter MANN W 712/73', taskId: '01KVEF1TCCBTWQR5CV6V05PQ81', parts: [
                new ActionPart('W 712/73', 'MANN-FILTER', 'Filtre à huile'),
            ]),
        ]);

        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Référence filtre à huile',
            'actions' => [[
                'label' => 'Ajouter MANN W 712/73',
                'patchTask' => [
                    'id' => '/garage/planned-tasks/01KVEF1TCCBTWQR5CV6V05PQ81',
                    'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER', 'label' => 'Filtre à huile']],
                ],
            ]],
        ], json_decode($published->getData(), true));
    }

    public function testFocusViewPublishesPanelAndSpotlightKeys(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('tasks', ['oil_change', 'timing_belt']);

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(
            ['type' => 'focusView', 'panel' => 'tasks', 'keys' => ['oil_change', 'timing_belt']],
            json_decode($published->getData(), true),
        );
    }

    public function testShowDocumentPublishesTheViewerFrame(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('ok', $tools->showDocument('/garage/vehicles/01J/documents/02K/file', 'MR 361', 'application/pdf', 'mr361.pdf', 2, ' capacité huile '));

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame([
            'type' => 'showDocument',
            'url' => '/garage/vehicles/01J/documents/02K/file',
            'title' => 'MR 361',
            'contentType' => 'application/pdf',
            'filename' => 'mr361.pdf',
            'page' => 2,
            'highlight' => 'capacité huile',
        ], json_decode($published->getData(), true));
    }

    public function testShowDocumentOmitsAnAbsentPageAndABlankHighlight(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->showDocument('/garage/vehicles/01J/documents/02K/file', 'MR 361', 'application/pdf', 'mr361.pdf', null, '  ');

        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'showDocument',
            'url' => '/garage/vehicles/01J/documents/02K/file',
            'title' => 'MR 361',
            'contentType' => 'application/pdf',
            'filename' => 'mr361.pdf',
        ], json_decode($published->getData(), true));
    }

    public function testFocusViewOmitsKeysWhenNoneGiven(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('specs');

        $this->assertNotNull($published);
        // No keys → the spotlight raises the panel but lights no rows/regions (no empty `keys` array).
        $this->assertSame(['type' => 'focusView', 'panel' => 'specs'], json_decode($published->getData(), true));
    }

    public function testFocusViewRaisesTheCarnetPanel(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('carnet');

        $this->assertNotNull($published);
        $this->assertSame(['type' => 'focusView', 'panel' => 'carnet'], json_decode($published->getData(), true));
    }

    public function testFocusViewRaisesTheDocumentationPanel(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('docs');

        $this->assertNotNull($published);
        $this->assertSame(['type' => 'focusView', 'panel' => 'docs'], json_decode($published->getData(), true));
    }

    public function testShowFactProjectsFetchedNodesAlongsideTheSpotlightKeys(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        // The nodes ARE the panel; the key only lights the moteur region (the client contract).
        $tools->showFact(['oil_change'], 'Capacités fluides', [
            new SpecNode('oil_capacity', 'Huile moteur', '4,3 l', true, 'ACEA E3|E4|E5'),
        ]);

        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'showFact',
            'keys' => ['oil_change'],
            'title' => 'Capacités fluides',
            'nodes' => [['key' => 'oil_capacity', 'label' => 'Huile moteur', 'value' => '4,3 l', 'verified' => true, 'info' => 'ACEA E3|E4|E5']],
        ], json_decode($published->getData(), true));
    }

    public function testShowFactWithoutNodesStaysTheStoredFactFilter(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->showFact(['oil_capacity']);

        $this->assertNotNull($published);
        $this->assertSame(['type' => 'showFact', 'keys' => ['oil_capacity']], json_decode($published->getData(), true));
    }

    public function testFocusViewRejectsUnknownPanel(): void
    {
        $tools = new RenderIntentTools($this->createStub(HubInterface::class), $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->expectException(\InvalidArgumentException::class);
        $tools->focusView('engine');
    }

    public function testProposeActionsRejectsAButtonThatNeitherInstructsNorWrites(): void
    {
        $tools = new RenderIntentTools($this->createStub(HubInterface::class), $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->expectException(\InvalidArgumentException::class);
        $tools->proposeActions('Choix', [new ActionButton('Bouton mort')]);
    }

    public function testProposeActionsPublishesButtonsFromTypedActions(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create(), new TestRenderTargets([self::VEHICLE], [self::TASK]));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->proposeActions('Entretien à 250 000 km', [
            new ActionButton('Liste de courses', 'prépare la liste de courses pour la vidange'),
        ], 'Trouvé en ligne : vidange');

        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange']],
            'finding' => 'Trouvé en ligne : vidange',
        ], json_decode($published->getData(), true));
    }
}
