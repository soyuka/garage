<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AgentTurnRateLimitTest extends AppTestCase
{
    public function testATurnOverTheOwnersLimitIs429WithRetryAfterAndNeverStarts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();

        $invocations = 0;
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use (&$invocations): string {
            ++$invocations;

            return 'done';
        }));

        self::exhaust($container->get('limiter.agent_turn'), self::TEST_OWNER);

        $response = $client->request('POST', '/agent/turn', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-limited'],
        ]);

        $this->assertResponseStatusCodeSame(429);
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        $this->assertNotNull($retryAfter, '429 carries Retry-After');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertSame(0, $invocations, 'the model is never called for a refused turn');
    }

    public function testTheLimitIsPerOwner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $container->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'done'));

        self::exhaust($container->get('limiter.agent_turn'), self::TEST_OWNER);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders('someone-else', 'someone-else@example.com') + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-other'],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    private static function exhaust(RateLimiterFactoryInterface $factory, string $owner): void
    {
        $limiter = $factory->create($owner);
        for ($i = 0; $i < 10_000 && $limiter->consume()->isAccepted(); ++$i) {
        }
    }
}
