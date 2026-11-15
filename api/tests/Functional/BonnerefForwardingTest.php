<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\RunAgentTurn;
use App\Tests\Mock\FixtureHttpClient;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Messenger\MessageBusInterface;

final class BonnerefForwardingTest extends AppTestCase
{
    use McpClientTrait;

    private const string SERVICE_BEARER = 'Bearer '.FixtureHttpClient::SERVICE_TOKEN;

    public function testAnMcpCatalogueToolCallsAsTheServiceOnBehalfOfTheCaller(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', ['name' => 'list_makes', 'arguments' => ['search' => 'maz']], 2);

        $sent = static::bonneref()->lastRequest()['headers'] ?? [];
        $this->assertSame(self::SERVICE_BEARER, $sent['authorization'] ?? null, 'the caller\'s MCP token must never reach bonneref');
        $this->assertSame(self::TEST_OWNER, $sent['x-bonneref-owner'] ?? null);
    }

    public function testAnMcpCatalogueToolForwardsTheLanguageOfTheMcpRequest(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        foreach (['en-GB,en;q=0.9' => 'en', 'fr-FR,fr;q=0.9' => 'fr', 'de-DE,de;q=0.9' => 'en'] as $accept => $expected) {
            $client->request('POST', '/mcp', [
                'headers' => $this->mcpHeaders(['Mcp-Session-Id' => $sessionId, 'Accept-Language' => $accept]),
                'json' => ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_makes', 'arguments' => ['search' => 'maz']]],
            ]);

            $this->assertResponseIsSuccessful();
            $this->assertSame($expected, static::bonneref()->lastRequest()['headers']['accept-language'], $accept);
        }
    }

    public function testAnMcpCatalogueToolWithoutAnyLanguageForwardsEnglish(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $client->request('POST', '/mcp', [
            'headers' => $this->mcpHeaders(['Mcp-Session-Id' => $sessionId, 'Accept-Language' => '']),
            'json' => ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_makes', 'arguments' => ['search' => 'maz']]],
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertSame('en', static::bonneref()->lastRequest()['headers']['accept-language']);
    }

    public function testAQueuedTurnWithoutALocaleForwardsEnglish(): void
    {
        static::createClient();
        $container = static::getContainer();
        static::catalogue();
        $container->set('ai.platform.anthropic', $this->callingListPartCategories());

        $container->get(MessageBusInterface::class)->dispatch(new RunAgentTurn(
            instruction: 'Which part categories?',
            sessionTopic: 'https://localhost/agent/sessions/sess-worker-default-language',
            owner: self::TEST_OWNER,
        ));

        $this->assertSame('en', static::bonneref()->lastRequest()['headers']['accept-language']);
    }

    public function testAQueuedTurnForwardsItsInterfaceLanguageToTheCatalogueTools(): void
    {
        static::createClient();
        $container = static::getContainer();
        static::catalogue();
        $container->set('ai.platform.anthropic', $this->callingListPartCategories());

        $container->get(MessageBusInterface::class)->dispatch(new RunAgentTurn(
            instruction: 'Which part categories?',
            sessionTopic: 'https://localhost/agent/sessions/sess-worker-language',
            owner: self::TEST_OWNER,
            locale: 'en',
        ));

        $this->assertSame('en', static::bonneref()->lastRequest()['headers']['accept-language']);
    }

    public function testAQueuedTurnCallsTheCatalogueAsTheServiceOnBehalfOfItsOwner(): void
    {
        static::createClient();
        $container = static::getContainer();
        static::catalogue();
        $container->set('ai.platform.anthropic', $this->callingListPartCategories());

        $container->get(MessageBusInterface::class)->dispatch(new RunAgentTurn(
            instruction: 'Quelles catégories de pièces ?',
            sessionTopic: 'https://localhost/agent/sessions/sess-worker-token',
            owner: self::TEST_OWNER,
        ));

        $sent = static::bonneref()->lastRequest()['headers'] ?? [];
        $this->assertSame(self::SERVICE_BEARER, $sent['authorization'] ?? null);
        $this->assertSame(self::TEST_OWNER, $sent['x-bonneref-owner'] ?? null);
    }

    public function testAPostedTurnNeverForwardsTheBearerOfItsRequest(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        static::catalogue();
        $container->set('ai.platform.anthropic', $this->callingListPartCategories());

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Quelles catégories de pièces ?', 'sessionId' => 'sess-posted-token'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertSame(self::SERVICE_BEARER, static::bonneref()->lastRequest()['headers']['authorization'] ?? null);
    }

    private function callingListPartCategories(): ScriptedStreamPlatform
    {
        return new ScriptedStreamPlatform(static function ($model, $input): ToolCallResult|string {
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    return 'Fait.';
                }
            }

            return new ToolCallResult([new ToolCall('c1', 'list_part_categories', ['carId' => 34247, 'carType' => 'CV', 'search' => 'filter'])]);
        });
    }
}
