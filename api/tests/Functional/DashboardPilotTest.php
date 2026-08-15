<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\RecordingHub;

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

        foreach (['select_vehicle', 'toast', 'show_fact', 'focus_view', 'propose_actions', 'narrate_step', 'settle_dashboard'] as $tool) {
            $this->assertContains($tool, $tools);
        }
    }

    public function testTheAdvertisedSchemasCarryTheCarnetPanelAndTheProjectedNodes(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $schemas = array_column($tools, 'inputSchema', 'name');

        $this->assertSame(
            ['tasks', 'specs', 'carnet'],
            $schemas['focus_view']['properties']['panel']['enum'] ?? null,
            'focus_view must advertise the carnet panel — otherwise the agent cannot raise the history/spend projection.',
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

    public function testFocusViewStillRejectsAPanelTheDashboardCannotRaise(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'focus_view',
            'arguments' => ['panel' => 'parc'],
        ], 3);

        $this->assertStringContainsString('invalid_input', json_encode($result));
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

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'select_vehicle',
            'arguments' => ['vehicle' => '/garage/vehicles/01J'],
        ], 3);

        $this->assertSame(
            ['type' => 'selectVehicle', 'id' => '/garage/vehicles/01J'],
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

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'propose_actions',
            'arguments' => [
                'headline' => 'Références filtre à huile',
                'finding' => '2 références compatibles',
                'actions' => [
                    ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
                    ['label' => 'Ajouter MANN W 712/73', 'taskId' => '01JTASK', 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]],
                ],
            ],
        ], 3);

        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Références filtre à huile',
            'actions' => [
                ['label' => 'Chercher au catalogue', 'instruction' => 'cherche les références'],
                ['label' => 'Ajouter MANN W 712/73', 'patchTask' => ['id' => '/garage/planned-tasks/01JTASK', 'parts' => [['ref' => 'W 712/73', 'brand' => 'MANN-FILTER']]]],
            ],
            'finding' => '2 références compatibles',
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

    public function testTheLinkRequiresAToken(): void
    {
        static::createClient()->request('GET', '/agent/link');

        $this->assertResponseStatusCodeSame(401);
    }

}
