<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

/**
 * The worker runs the garage turn in streaming mode. A non-stream Anthropic call holds the connection
 * until the whole response is built and trips Symfony HttpClient's 60s idle timeout on long turns
 * (web_search + Motul + plan_maintenance) — the turn is killed, retried, dead-lettered, and the
 * dashboard never gets a render intent. Streaming keeps bytes flowing so the idle timer never fires.
 *
 * Asserts the two properties the fix rests on: the agent is invoked with the stream option, and the
 * handler DRAINS the lazy stream — a StreamResult runs nothing until consumed, so draining is what
 * executes the tool-call loop and publishes the render intents.
 */
final class AgentTurnStreamingTest extends AppTestCase
{
    public function testWorkerStreamsTheTurnAndDrainingRunsTheToolLoop(): void
    {
        static::createClient();
        $container = static::getContainer();

        $receivedOptions = [];
        // Scripted LLM: 1st call → a toast tool call; 2nd → finish. ScriptedStreamPlatform delivers
        // each as a stream, mirroring the real Anthropic platform under stream:true.
        $platform = new ScriptedStreamPlatform(static function ($model, $input, array $options) use (&$receivedOptions): ToolCallResult|string {
            $receivedOptions = $options;

            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'toast', [
                    'message' => 'Viscosité 5W-30.',
                    'tone' => 'ok',
                ])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $handler = $container->get(RunAgentTurnHandler::class);
        $handler(new RunAgentTurn(
            instruction: 'Quelle viscosité pour la Mazda 6 ?',
            sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-stream'),
            owner: self::TEST_OWNER,
        ));

        self::assertTrue($receivedOptions['stream'] ?? false, 'the worker must invoke the agent in streaming mode');

        // Draining the stream ran the tool loop, which executed the toast tool and published it.
        $hub = $container->get(RecordingHub::class);
        $payloads = array_map(static fn ($u) => json_decode($u->getData(), true), $hub->updates);
        $toasts = array_filter($payloads, static fn ($p) => 'toast' === ($p['type'] ?? null));
        self::assertNotEmpty($toasts, 'draining the stream must run the tool loop and emit the toast render intent');
        self::assertSame('Viscosité 5W-30.', array_values($toasts)[0]['message']);
    }
}
