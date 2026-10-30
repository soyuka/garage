<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use App\Tests\Mock\ScriptedStreamPlatform;

/**
 * A malformed tool call (here ask_question with an option missing its `value`) must NOT kill the
 * turn with a 500: fault tolerance turns the validation failure into an error tool-result fed back
 * to the model, which can then recover. Before fault_tolerant_toolbox was re-enabled this 500'd.
 */
final class AgentTurnFaultToleranceTest extends AppTestCase
{
    public function testMalformedToolCallDoesNotKillTheTurn(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // 1st turn → malformed ask_question (option lacks `value`); 2nd → finish.
        $modelCalls = 0;
        $platform = new ScriptedStreamPlatform(static function ($model, $input) use (&$modelCalls): ToolCallResult|string {
            ++$modelCalls;
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'ask_question', [
                    'question' => 'Avant ou arrière ?',
                    'options' => [['label' => 'Avant']],
                    'context' => 'freins',
                ])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'mes freins grincent', 'sessionId' => 'sess-ft'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertSame(2, $modelCalls, 'the malformed call came back to the model as a tool error and the turn went on');
    }
}
