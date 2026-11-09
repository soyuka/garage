<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Ui\DashboardChannel;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Tests\Mock\RecordingHub;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * The pilot channel: an external MCP host (Claude Code, Goose…) drives the SAME dashboard the in-app
 * agent drives, without going through /agent/turn. The render intents are the identical UIAction
 * frames; only the topic differs — a per-owner pilot topic instead of the per-tab turn session, since
 * an MCP caller has no tab and no session id. The dashboard learns that topic from GET /agent/link
 * and subscribes to it alongside its own session.
 */
final class DashboardPilotTest extends AppTestCase
{
    use McpClientTrait;
    use PilotFrameTrait;

    public function testDashboardToolsAreDiscoverableOverMcp(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name');

        foreach (['select_vehicle', 'toast', 'show_fact', 'focus_view', 'propose_actions', 'narrate_step', 'settle_dashboard', 'reveal_assembly'] as $tool) {
            $this->assertContains($tool, $tools);
        }
    }

    public function testRevealAssemblyGhostsTheShellAndRaisesTheRegionDiagram(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'reveal_assembly',
            'arguments' => ['region' => 'bay', 'partKeys' => ['fuel_filter']],
        ], 3);

        $this->assertSame(
            ['type' => 'revealAssembly', 'region' => 'bay', 'partKeys' => ['fuel_filter']],
            $this->lastPilotFrame(),
        );
    }

    public function testRevealAssemblyNeedsNoPartKeys(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'reveal_assembly',
            'arguments' => ['region' => 'hood'],
        ], 3);

        // The circuit alone is a valid answer to "where does this sit", so an omitted partKeys must not
        // become an empty array on the wire: the client's default is "call nothing out", not "call out
        // nothing", and the two would be indistinguishable if the key were always present.
        $this->assertSame(['type' => 'revealAssembly', 'region' => 'hood'], $this->lastPilotFrame());
    }

    public function testRevealAssemblyRejectsARegionTheCarDoesNotHave(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $error = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'reveal_assembly',
            'arguments' => ['region' => 'coffre'],
        ], 3));

        $this->assertSame('invalid_input', $error['error'], 'an argument off the input schema is a tool error the model can fix');
        $this->assertStringContainsString('"hood"', $error['message']);
        $this->assertNull($this->lastPilotFrame());
    }

    public function testRevealAssemblyAdvertisesTheRegionsTheClientCanDraw(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $schemas = [];
        foreach ($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'] as $tool) {
            $schemas[$tool['name']] = $tool['inputSchema'];
        }

        // A region the wire cannot name is a part of the car the agent can never point inside of.
        $this->assertSame(
            ['hood', 'bay', 'windshield', 'frontAxle', 'rearAxle'],
            $schemas['reveal_assembly']['properties']['region']['enum'] ?? null,
        );
    }

    public function testTheAdvertisedSchemasCarryTheCarnetPanelAndTheProjectedNodes(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $schemas = array_column($tools, 'inputSchema', 'name');

        $this->assertSame(
            ['tasks', 'specs', 'carnet', 'parts', 'docs'],
            $schemas['focus_view']['properties']['panel']['enum'] ?? null,
            'focus_view must advertise every panel the client can render — a surface the wire cannot name is one the agent answers in prose instead.',
        );
        $this->assertArrayHasKey(
            'nodes',
            $schemas['show_fact']['properties'] ?? [],
            'show_fact must advertise nodes — otherwise fetched (non-stored) data has nowhere to land.',
        );
    }

    public function testFocusViewRaisesTheCarnetPanel(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'focus_view',
            'arguments' => ['panel' => 'carnet'],
        ], 3);

        $this->assertSame(['type' => 'focusView', 'panel' => 'carnet'], $this->lastPilotFrame());
    }

    public function testFocusViewRaisesTheDocumentationPanel(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'focus_view',
            'arguments' => ['panel' => 'docs'],
        ], 3);

        $this->assertSame(['type' => 'focusView', 'panel' => 'docs'], $this->lastPilotFrame());
    }

    public function testFocusViewStillRejectsAPanelTheDashboardCannotRaise(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $error = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => 'focus_view',
            'arguments' => ['panel' => 'parc'],
        ], 3));

        $this->assertSame('invalid_input', $error['error'], 'an argument off the input schema is a tool error the model can fix');
        $this->assertStringContainsString('"docs"', $error['message']);
        $this->assertNull($this->lastPilotFrame());
    }

    public function testShowFactProjectsFetchedNodesWithNoStoredFactKeys(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'show_fact',
            'arguments' => [
                'title' => 'Capacités fluides',
                'nodes' => [
                    ['key' => 'oil_capacity', 'label' => 'Huile moteur', 'value' => '4,3 l', 'verified' => true, 'info' => 'ACEA E3|E4|E5'],
                    ['key' => 'coolant_capacity', 'label' => 'Liquide de refroidissement', 'value' => '6,5 l'],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'showFact',
            'keys' => [],
            'title' => 'Capacités fluides',
            'nodes' => [
                ['key' => 'oil_capacity', 'label' => 'Huile moteur', 'value' => '4,3 l', 'verified' => true, 'info' => 'ACEA E3|E4|E5'],
                ['key' => 'coolant_capacity', 'label' => 'Liquide de refroidissement', 'value' => '6,5 l', 'verified' => false],
            ],
        ], $this->lastPilotFrame());
    }

    public function testShowFactKeepsFilteringStoredFactsWhenNoNodesAreGiven(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'show_fact',
            'arguments' => ['keys' => ['oil_capacity', 'oil_type'], 'title' => 'Huile moteur'],
        ], 3);

        $this->assertSame([
            'type' => 'showFact',
            'keys' => ['oil_capacity', 'oil_type'],
            'title' => 'Huile moteur',
        ], $this->lastPilotFrame());
    }

    public function testShowFactRejectsACallCarryingNeitherKeysNorNodes(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'show_fact',
            'arguments' => ['title' => 'Huile moteur'],
        ], 3);

        $this->assertStringContainsString('invalid_input', json_encode($result));
        $this->assertNull($this->lastPilotFrame());
    }

    public function testShowFactDropsANodeWithNoValueRatherThanProjectingAnEmptyRow(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'show_fact',
            'arguments' => [
                'nodes' => [
                    ['key' => 'oil_capacity', 'label' => 'Huile moteur', 'value' => '4,3 l'],
                    ['key' => 'gearbox_capacity', 'label' => 'Boîte', 'value' => '  '],
                ],
            ],
        ], 3);

        $this->assertSame([
            ['key' => 'oil_capacity', 'label' => 'Huile moteur', 'value' => '4,3 l', 'verified' => false],
        ], $this->lastPilotFrame()['nodes'] ?? null);
    }

    public function testSelectVehiclePublishesTheRenderIntentOnTheOwnerPilotTopic(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        [$vehicle] = $this->ownedTask();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'select_vehicle',
            'arguments' => ['vehicle' => '/garage/vehicles/'.$vehicle],
        ], 3);

        $this->assertSame(
            ['type' => 'selectVehicle', 'id' => '/garage/vehicles/'.$vehicle],
            $this->lastPilotFrame(),
        );
    }

    public function testNarrateStepPublishesAStatusFrameAndSettleEndsTheTurn(): void
    {
        $client = static::createClient();
        // A pilot run spans several tool calls; the kernel (and with it the RecordingHub) is rebooted
        // between requests by default, which would drop every frame but the last call's.
        $client->disableReboot();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'narrate_step',
            'arguments' => ['message' => 'Je relis le carnet…'],
        ], 3);
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'settle_dashboard',
            'arguments' => ['message' => 'Carnet à jour', 'tone' => 'ok'],
        ], 4);

        $this->assertSame(
            [
                ['type' => 'status', 'message' => 'Je relis le carnet…'],
                ['type' => 'toast', 'tone' => 'ok', 'message' => 'Carnet à jour'],
                ['type' => 'turn', 'phase' => 'end'],
            ],
            $this->pilotFrames(),
        );
    }

    public function testProposeActionsCarriesItsButtonsThrough(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        [, $task] = $this->ownedTask();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Références filtre à huile',
                'finding' => '2 références compatibles',
                'actions' => [
                    ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
                    ['label' => 'Ajouter MANN W 712/73', 'taskId' => $task, 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Références filtre à huile',
            'actions' => [
                ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
                ['label' => 'Ajouter MANN W 712/73', 'patchTask' => ['id' => '/garage/planned-tasks/'.$task, 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]]],
            ],
            'finding' => '2 références compatibles',
        ], $this->lastPilotFrame());
    }

    /**
     * Reproduces the live 2026-09-05 failure: claude-sonnet-4-6 filled BOTH kind-(2) fields with
     * explicit `null` on every kind-(1) button, since the tool description names them as the unused
     * side rather than as absent keys. `ActionButton::$parts` was a non-nullable `array`, so
     * hydrating `"parts": null` through the real {@see \Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver}
     * threw a TypeError before {@see \App\Agent\Ui\RenderIntentTools::proposeActions()} ever ran.
     */
    public function testProposeActionsAcceptsExplicitNullsForTheUnusedKind(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Entretien à 250 000 km',
                'actions' => [
                    ['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange', 'taskId' => null, 'parts' => null],
                    ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références', 'taskId' => null, 'parts' => null],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange'],
                ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
            ],
        ], $this->lastPilotFrame());
    }

    public function testProposeActionsAcceptsExplicitNullInstructionForADeterministicWrite(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);
        [, $task] = $this->ownedTask();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Références filtre à huile',
                'actions' => [
                    ['label' => 'Ajouter MANN W 712/73', 'instruction' => null, 'taskId' => $task, 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Références filtre à huile',
            'actions' => [
                ['label' => 'Ajouter MANN W 712/73', 'patchTask' => ['id' => '/garage/planned-tasks/'.$task, 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]]],
            ],
        ], $this->lastPilotFrame());
    }

    /**
     * A taskId with an EMPTY parts list is not a deterministic write (nothing to merge) — the
     * button falls back to its instruction. Existing behaviour, pinned so the nullability fix
     * above cannot flip it.
     */
    public function testProposeActionsFallsBackToInstructionWhenPartsIsEmpty(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Entretien à 250 000 km',
                'actions' => [
                    ['label' => 'Voir le détail', 'instruction' => 'détaille la tâche', 'taskId' => '01JTASK', 'parts' => []],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [
                ['label' => 'Voir le détail', 'instruction' => 'détaille la tâche'],
            ],
        ], $this->lastPilotFrame());
    }

    public function testAgentLinkGivesTheDashboardItsPilotTopic(): void
    {
        $client = static::createClient();

        $response = $client->request('GET', '/agent/link', ['headers' => self::authHeaders()]);

        $this->assertResponseIsSuccessful();
        $topic = json_decode($response->getContent(), true)['topic'] ?? null;
        $this->assertIsString($topic);
        $this->assertStringContainsString('/agent/pilots/', $topic);

        // The topic the dashboard subscribes to is the one an MCP render intent lands on.
        $sessionId = $this->initialize($client);
        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'toast',
            'arguments' => ['message' => 'Coucou', 'tone' => 'info'],
        ], 3);

        $hub = static::getContainer()->get(RecordingHub::class);
        $this->assertNotEmpty($hub->updatesForTopic($topic));
    }

    public function testAgentLinkGivesTheDashboardItsFleetTopic(): void
    {
        $response = static::createClient()->request('GET', '/agent/link', ['headers' => self::authHeaders()]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            static::getContainer()->get(DashboardChannel::class)->fleetTopic(self::TEST_OWNER),
            json_decode($response->getContent(), true)['fleetTopic'] ?? null,
        );
    }

    public function testTheLinkRequiresAToken(): void
    {
        static::createAnonymousClient()->request('GET', '/agent/link');

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @return array{string, string} the vehicle and open task ULIDs, owned by the test owner
     */
    private function ownedTask(): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'ESPACE', VehicleType::Car, 'test');
        $vehicle->setOwner(self::TEST_OWNER);
        $task = new PlannedMaintenance($vehicle, 'oil_change', 'test');
        $dm->persist($vehicle);
        $dm->persist($task);
        $dm->flush();

        return [(string) $vehicle->getId(), (string) $task->getId()];
    }
}
