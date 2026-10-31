<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class AgentTurnCappedDeadLetterTest extends AppTestCase
{
    public function testATurnThatHitsTheToolCallCapSkipsTheRetryPolicy(): void
    {
        static::createClient();
        $container = static::getContainer();

        $calls = 0;
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use (&$calls): ToolCallResult {
            return new ToolCallResult([new ToolCall('c'.++$calls, 'list_makes', ['offset' => $calls])]);
        }));

        $handler = $container->get(RunAgentTurnHandler::class);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new RunAgentTurn(
            instruction: 'filtre à huile',
            sessionTopic: 'https://localhost/agent/sessions/sess-capped',
            owner: self::TEST_OWNER,
        ));
    }
}
