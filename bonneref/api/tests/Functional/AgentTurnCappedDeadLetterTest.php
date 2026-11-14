<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;
use App\Agent\Ui\DashboardChannel;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Security\OwnerContext;
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

    public function testAFailedTurnIsNotRetriedSinceItsPhotosAreGone(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function (): never {
            throw new \RuntimeException('provider exploded');
        }));

        try {
            $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
                instruction: 'filtre à huile',
                sessionTopic: 'https://localhost/agent/sessions/sess-failed',
                owner: self::TEST_OWNER,
            ));
            $this->fail('a failed turn must throw');
        } catch (UnrecoverableMessageHandlingException $e) {
            $this->assertSame('provider exploded', $e->getPrevious()?->getMessage());
        }
    }

    public function testTheTurnOwnerDoesNotOutliveTheTurn(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): ToolCallResult => new ToolCallResult([new ToolCall('e1', 'end_turn', [])])));

        $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: 'filtre à huile',
            sessionTopic: 'https://localhost/agent/sessions/sess-owner',
            owner: 'someone-else@example.com',
        ));

        $this->assertNotSame('someone-else@example.com', $container->get(OwnerContext::class)->current());
    }

    public function testNoBindingOutlivesATurnThatFailsBeforeTheAgentRuns(): void
    {
        static::createClient();
        $container = static::getContainer();
        $container->set(TurnCancellations::class, new class implements TurnCancellations {
            public function request(string $sessionId): void
            {
            }

            public function isRequested(string $sessionId): bool
            {
                return false;
            }

            public function clear(string $sessionId): void
            {
                throw new \RuntimeException('store down');
            }
        });

        try {
            $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
                instruction: 'filtre à huile',
                sessionTopic: 'https://localhost/agent/sessions/sess-early-failure',
                owner: 'someone-else@example.com',
                locale: Locale::Fr->value,
            ));
            $this->fail('the store failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('store down', $e->getMessage());
        }

        $this->assertNotSame('someone-else@example.com', $container->get(OwnerContext::class)->current());
        $this->assertNull($container->get(DashboardChannel::class)->sessionId());
        $this->assertSame(Locale::DEFAULT, $container->get(LocaleContext::class)->current());
    }
}
