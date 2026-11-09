<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;
use App\Agent\Ui\TurnSessionTopic;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\ScriptedStreamPlatform;

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

        $platform = new ScriptedStreamPlatform(static fn (): string => 'Fait.');
        $container->set('ai.platform.anthropic', $platform);

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
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

        $platform = new ScriptedStreamPlatform(static function (): string {
            throw new \RuntimeException('boom');
        });
        $container->set('ai.platform.anthropic', $platform);

        try {
            $client->request('POST', '/agent/turn', [
                'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
                'json' => ['instruction' => 'Bonjour', 'sessionId' => 'sess-boom'],
            ]);
            // @mago-expect lint:no-empty-catch-clause the worker rethrows by design; the published frames are asserted below
        } catch (\Throwable) {
            // The worker rethrows (dead-lettered, never retried); we only care that `end` was published first.
        }

        $phases = $this->turnPhases($container->get(RecordingHub::class), 'sess-boom');
        $this->assertContains('end', $phases, 'expected a turn end even when the agent loop throws');
    }

    public function testTurnFailingBeforeItsLoopEndsFailedOnTheMessengerPath(): void
    {
        $client = static::createClient();
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
            $client->request('POST', '/agent/turn', [
                'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
                'json' => ['instruction' => 'Bonjour', 'sessionId' => 'sess-early'],
            ]);
            // @mago-expect lint:no-empty-catch-clause the worker rethrows by design; the published frames are asserted below
        } catch (\Throwable) {
        }

        $frames = array_values(array_filter(
            array_map(static fn ($u): array => json_decode($u->getData(), true), $container->get(RecordingHub::class)->updates),
            static fn (array $f): bool => 'turn' === ($f['type'] ?? null),
        ));
        $this->assertSame([['type' => 'turn', 'phase' => 'end', 'outcome' => 'failed']], $frames);
    }

    /**
     * @return list<string> the ordered `phase` values of the `turn` lifecycle frames on the topic
     */
    private function turnPhases(RecordingHub $hub, string $sessionId): array
    {
        $topic = static::getContainer()->get(TurnSessionTopic::class)->topic(self::TEST_OWNER, $sessionId);
        $phases = [];
        foreach ($hub->updates as $update) {
            if ($topic !== ($update->getTopics()[0] ?? '')) {
                continue;
            }
            $payload = json_decode($update->getData(), true);
            $this->assertIsArray($payload);
            if ('turn' === ($payload['type'] ?? null)) {
                $phases[] = (string) $payload['phase'];
            }
        }

        return $phases;
    }
}
