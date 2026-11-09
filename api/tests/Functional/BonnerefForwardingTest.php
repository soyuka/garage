<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Bonneref\BonnerefToken;
use App\Message\RunAgentTurn;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Messenger\MessageBusInterface;

final class BonnerefForwardingTest extends AppTestCase
{
    use McpClientTrait;

    public function testAnMcpCatalogueToolForwardsTheBearerOfTheMcpRequest(): void
    {
        $client = static::createClient();
        static::catalogue();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', ['name' => 'list_makes', 'arguments' => ['search' => 'maz']], 2);

        $this->assertSame($this->mcpHeaders()['Authorization'], static::bonneref()->lastRequest()['headers']['authorization']);
    }

    public function testAQueuedTurnForwardsTheTokenItCarriesToTheCatalogueTools(): void
    {
        static::createClient();
        $container = static::getContainer();
        static::catalogue();
        $container->set('ai.platform.anthropic', $this->callingListPartCategories());

        $container->get(MessageBusInterface::class)->dispatch(new RunAgentTurn(
            instruction: 'Quelles catégories de pièces ?',
            sessionTopic: 'https://localhost/agent/sessions/sess-worker-token',
            owner: self::TEST_OWNER,
            // @mago-expect lint:no-literal-password test fixture
            accessToken: 'queued-token',
        ));

        $this->assertSame('Bearer queued-token', static::bonneref()->lastRequest()['headers']['authorization']);
        $this->assertNull($container->get(BonnerefToken::class)->get(), 'the token must not outlive the turn');
    }

    public function testAPostedTurnQueuesTheBearerOfItsRequest(): void
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
        $this->assertSame(self::authHeaders()['Authorization'], static::bonneref()->lastRequest()['headers']['authorization']);
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
