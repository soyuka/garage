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
 * The worker runs the finder turn in streaming mode. A non-stream Anthropic call holds the connection
 * until the whole response is built and trips Symfony HttpClient's 60s idle timeout on long turns
 * (web_search + several catalogue lookups) — the turn is killed, retried, dead-lettered, and the
 * dashboard never gets a render frame. Streaming keeps bytes flowing so the idle timer never fires.
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
        // Scripted LLM: 1st call → an ask_question tool call; 2nd → finish. ScriptedStreamPlatform delivers
        // each as a stream, mirroring the real Anthropic platform under stream:true.
        $platform = new ScriptedStreamPlatform(function ($model, $input, array $options) use (&$receivedOptions): ToolCallResult|string {
            $receivedOptions = $options;

            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'ask_question', [
                    'question' => 'Les freins avant ou arrière ?',
                    'options' => [['label' => 'Avant', 'value' => 'avant'], ['label' => 'Arrière', 'value' => 'arriere']],
                    'context' => 'freins : essieu ?',
                ])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $handler = $container->get(RunAgentTurnHandler::class);
        $handler(new RunAgentTurn(
            instruction: 'mes freins grincent',
            sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess-stream'),
            owner: self::TEST_OWNER,
        ));

        self::assertTrue($receivedOptions['stream'] ?? false, 'the worker must invoke the agent in streaming mode');

        // Draining the stream ran the tool loop, which executed ask_question and published its frame.
        $hub = $container->get(RecordingHub::class);
        $payloads = array_map(static fn ($u) => json_decode($u->getData(), true), $hub->updates);
        $questions = array_values(array_filter($payloads, static fn ($p) => 'askQuestion' === ($p['type'] ?? null)));
        self::assertNotEmpty($questions, 'draining the stream must run the tool loop and emit the askQuestion frame');
        self::assertSame('Les freins avant ou arrière ?', $questions[0]['question']);
    }
}
