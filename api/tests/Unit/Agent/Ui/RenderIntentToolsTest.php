<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\ActionButton;
use App\Agent\Ui\ActionPart;
use App\Agent\Ui\RenderIntentTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RenderIntentToolsTest extends TestCase
{
    public function testPublishFailureIsBestEffortAndDoesNotBreakTheTurn(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('Failed to send an update.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $tools = new RenderIntentTools($hub, $logger);
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('ok', $tools->toast('Entretien enregistré', 'ok'));
    }

    public function testPublishSerialisesTheTypedIntentToTheBoundSessionTopic(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->selectVehicle('/garage/vehicles/01J');

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(['type' => 'selectVehicle', 'id' => '/garage/vehicles/01J'], json_decode($published->getData(), true));
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
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
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

    public function testProposeActionsSchemaDescribesEachActionAsTypedObject(): void
    {
        $schema = (new Factory())->buildParameters(RenderIntentTools::class, 'proposeActions');

        $items = $schema['properties']['actions']['items'];
        $this->assertSame('object', $items['type']);
        $this->assertSame('string', $items['properties']['label']['type']);
        // instruction/taskId are nullable, so the unused side can be passed as null.
        $this->assertContains('null', (array) $items['properties']['instruction']['type']);
        $this->assertContains('null', (array) $items['properties']['taskId']['type']);

        $part = $items['properties']['parts']['items'];
        $this->assertSame('object', $part['type']);
        $this->assertSame('string', $part['properties']['ref']['type']);
    }

    public function testProposeActionsPublishesAStructuredPatchTaskWrite(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
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
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('tasks', ['oil_change', 'timing_belt']);

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(
            ['type' => 'focusView', 'panel' => 'tasks', 'keys' => ['oil_change', 'timing_belt']],
            json_decode($published->getData(), true),
        );
    }

    public function testFocusViewOmitsKeysWhenNoneGiven(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->focusView('specs');

        $this->assertNotNull($published);
        // No keys → the spotlight raises the panel but lights no rows/regions (no empty `keys` array).
        $this->assertSame(['type' => 'focusView', 'panel' => 'specs'], json_decode($published->getData(), true));
    }

    public function testFocusViewRejectsUnknownPanel(): void
    {
        $tools = new RenderIntentTools($this->createStub(HubInterface::class), $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->expectException(\InvalidArgumentException::class);
        $tools->focusView('engine');
    }

    public function testProposeActionsRejectsAButtonThatNeitherInstructsNorWrites(): void
    {
        $tools = new RenderIntentTools($this->createStub(HubInterface::class), $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->expectException(\InvalidArgumentException::class);
        $tools->proposeActions('Choix', [new ActionButton('Bouton mort')]);
    }

    public function testProposeActionsPublishesButtonsFromTypedActions(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
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
