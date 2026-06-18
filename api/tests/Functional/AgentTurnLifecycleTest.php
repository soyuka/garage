<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\RecordingHub;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * The dashboard's progress heartbeat needs to know when the worker turn is still running and when it
 * has settled — a signal the HTTP layer no longer carries since the turn moved to an async Messenger
 * worker (the request acks 202 immediately). The worker therefore streams a `turn` lifecycle intent
 * on the per-session topic: `start` when it begins and `end` once it settles. The `end` MUST be
 * published even when the agent loop throws, or the dashboard would stay stuck in the busy state.
 */
final class AgentTurnLifecycleTest extends AppTestCase
{
    public function testTurnStreamsStartAndEndLifecycleIntents(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $platform = new InMemoryPlatform(static fn (): string => 'Fait.');
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Bonjour', 'sessionId' => 'sess-life'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $phases = $this->turnPhases($container->get(RecordingHub::class), 'sess-life');
        $this->assertSame(['start', 'end'], $phases, 'expected a turn start then end on the session topic');
    }

    public function testTurnEndIsStreamedEvenWhenTheAgentThrows(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $platform = new InMemoryPlatform(static function (): string {
            throw new \RuntimeException('boom');
        });
        $container->set('ai.platform.anthropic', $platform);

        try {
            $client->request('POST', '/agent/turn', [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['instruction' => 'Bonjour', 'sessionId' => 'sess-boom'],
            ]);
        } catch (\Throwable) {
            // The worker rethrows so Messenger can retry; we only care that `end` was published first.
        }

        $phases = $this->turnPhases($container->get(RecordingHub::class), 'sess-boom');
        $this->assertContains('end', $phases, 'expected a turn end even when the agent loop throws');
    }

    /**
     * @return list<string> the ordered `phase` values of the `turn` lifecycle frames on the topic
     */
    private function turnPhases(RecordingHub $hub, string $sessionId): array
    {
        $phases = [];
        foreach ($hub->updates as $update) {
            if (!str_contains($update->getTopics()[0] ?? '', '/agent/sessions/'.$sessionId)) {
                continue;
            }
            $payload = json_decode($update->getData(), true);
            if ('turn' === ($payload['type'] ?? null)) {
                $phases[] = $payload['phase'];
            }
        }

        return $phases;
    }
}
