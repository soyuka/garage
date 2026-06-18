<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * A malformed tool call (here propose_actions with an action missing its `label`) must NOT kill the
 * turn with a 500: fault tolerance turns the validation failure into an error tool-result fed back
 * to the model, which can then recover. Before fault_tolerant_toolbox was re-enabled this 500'd.
 */
final class AgentTurnFaultToleranceTest extends AppTestCase
{
    public function testMalformedToolCallDoesNotKillTheTurn(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // 1st turn → malformed propose_actions (action lacks `label`); 2nd → finish.
        $platform = new InMemoryPlatform(static function ($model, $input): ToolCallResult|string {
            $executed = 0;
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    ++$executed;
                }
            }

            return match ($executed) {
                0 => new ToolCallResult([new ToolCall('c1', 'propose_actions', [
                    'headline' => 'Entretien',
                    'actions' => [['instruction' => 'prépare la liste']],
                ])]),
                default => 'Fait.',
            };
        });
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Propose des actions', 'sessionId' => 'sess-ft'],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }
}
