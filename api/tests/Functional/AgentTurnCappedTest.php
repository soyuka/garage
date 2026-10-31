<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * A runaway turn must be dead-lettered without retry: replaying it would spend the same expensive
 * tool loop three more times.
 */
final class AgentTurnCappedTest extends AppTestCase
{
    public function testARunawayTurnIsUnrecoverable(): void
    {
        static::createClient();
        $container = static::getContainer();

        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(
            static fn (): ToolCallResult => new ToolCallResult([new ToolCall(uniqid('c', true), 'list_vehicles', [])]),
        ));

        $handler = $container->get(RunAgentTurnHandler::class);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new RunAgentTurn(
            instruction: 'Liste mes véhicules',
            sessionTopic: 'https://localhost/agent/sessions/sess-capped',
            owner: self::TEST_OWNER,
        ));
    }
}
