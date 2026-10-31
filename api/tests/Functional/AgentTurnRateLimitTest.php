<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\ScriptedStreamPlatform;

/**
 * In-app turns spend our Anthropic credits on a public demo, so each owner gets a budget. The MCP
 * path is not limited: there the judge's own Claude does the reasoning.
 */
final class AgentTurnRateLimitTest extends AppTestCase
{
    public function testAnExhaustedOwnerGets429AndTheTurnNeverRuns(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $calls = 0;
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use (&$calls): string {
            ++$calls;

            return 'Fait.';
        }));

        $limiter = $container->get('limiter.agent_turn');
        $this->assertTrue($limiter->create(self::TEST_OWNER)->consume(30)->isAccepted(), 'the default budget is 30 turns');
        $this->assertFalse($limiter->create(self::TEST_OWNER)->consume()->isAccepted());

        $response = $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Quoi prévoir ?', 'sessionId' => 'sess-limit'],
        ]);

        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) ($response->getHeaders(false)['retry-after'][0] ?? 0));
        $this->assertSame(0, $calls, 'a refused turn must never reach the model');
    }
}
