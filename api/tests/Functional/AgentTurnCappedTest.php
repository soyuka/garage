<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * A turn is never retried: a replay re-runs its writes and spends the same expensive tool loop again.
 * Capped or failed, it goes straight to the `failed` transport.
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

    public function testAFailedTurnIsNotRetried(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function (): never {
            throw new \RuntimeException('provider exploded');
        }));

        try {
            $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
                instruction: 'Liste mes véhicules',
                sessionTopic: 'https://localhost/agent/sessions/sess-failed',
                owner: self::TEST_OWNER,
            ));
            $this->fail('a failed turn must throw');
        } catch (UnrecoverableMessageHandlingException $e) {
            $this->assertSame('provider exploded', $e->getPrevious()?->getMessage());
        }
    }

    public function testTheTurnTransportNeverRetries(): void
    {
        static::bootKernel();
        $strategy = static::getContainer()->get('messenger.retry.multiplier_retry_strategy.async');
        $envelope = new Envelope(new RunAgentTurn('x', 'https://localhost/agent/sessions/s', self::TEST_OWNER));

        $this->assertFalse($strategy->isRetryable($envelope, new \RuntimeException('transient')));
    }
}
