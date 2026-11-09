<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;
use App\Agent\Ui\DashboardChannel;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\Component\Mercure\Update;

/**
 * The turn session id is chosen by the browser, so on its own it scopes nothing: another owner
 * posting a turn or a cancel with the same id must neither reach this owner's session topic nor stop
 * this owner's turn. The topic and the cancel key are keyed digests of (owner, sessionId).
 */
final class AgentTurnSessionBindingTest extends AppTestCase
{
    private const string INTRUDER = 'intruder@example.com';
    private const string SESSION = 'sess-bound-0001';

    public function testATurnStreamsOnTheOwnerBoundSessionTopic(): void
    {
        $client = static::createClient();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'Fait.'));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Bonjour', 'sessionId' => self::SESSION],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $channel = static::getContainer()->get(DashboardChannel::class);
        $topic = $channel->sessionTopic(self::TEST_OWNER, self::SESSION);
        $hub = static::getContainer()->get(RecordingHub::class);

        $this->assertNotEmpty($hub->updatesForTopic($topic));
        $this->assertStringNotContainsString(self::SESSION, $topic);
        $this->assertNotSame($topic, $channel->sessionTopic(self::INTRUDER, self::SESSION));
        foreach ($hub->updates as $update) {
            $this->assertNotContains('https://localhost/agent/sessions/'.self::SESSION, $update->getTopics());
        }
    }

    public function testTheLinkHandsOutTheSessionTopic(): void
    {
        $response = static::createClient()->request('GET', '/agent/link?session='.self::SESSION, ['headers' => self::authHeaders()]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            static::getContainer()->get(DashboardChannel::class)->sessionTopic(self::TEST_OWNER, self::SESSION),
            $response->toArray()['sessionTopic'] ?? null,
        );
    }

    public function testCancelOnlyReachesTheCallersOwnTurn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $channel = static::getContainer()->get(DashboardChannel::class);
        $cancellations = static::getContainer()->get(TurnCancellations::class);
        $mine = $channel->sessionKey(self::TEST_OWNER, self::SESSION);
        $theirs = $channel->sessionKey(self::INTRUDER, self::SESSION);

        $client->request('POST', '/agent/turn/cancel', [
            'headers' => self::authHeaders('intruder-sub', self::INTRUDER) + ['Content-Type' => 'application/json'],
            'json' => ['sessionId' => self::SESSION],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertFalse($cancellations->isRequested($mine), 'another owner cannot stop this owner\'s turn');
        $this->assertTrue($cancellations->isRequested($theirs));
        $cancellations->clear($theirs);
    }

    public function testAMalformedSessionIdIsRefused(): void
    {
        $client = static::createClient();

        foreach (['../pilots/x', str_repeat('a', 129), 'a b'] as $sessionId) {
            $client->request('POST', '/agent/turn', [
                'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
                'json' => ['instruction' => 'Bonjour', 'sessionId' => $sessionId],
            ]);
            $this->assertResponseStatusCodeSame(400, $sessionId);

            $client->request('POST', '/agent/turn/cancel', [
                'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
                'json' => ['sessionId' => $sessionId],
            ]);
            $this->assertResponseStatusCodeSame(400, $sessionId);
        }

        $this->assertSame([], array_filter(static::getContainer()->get(RecordingHub::class)->updates, static fn (Update $u): bool => str_contains($u->getTopics()[0] ?? '', '/agent/sessions/')));
    }
}
