<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;

/**
 * POST /agent/turn/cancel raises the cooperative stop flag the worker's CancelTurnListener honours.
 */
final class AgentTurnCancelTest extends AppTestCase
{
    public function testCancelRaisesTheFlagForTheSession(): void
    {
        $client = static::createClient();
        $cancellations = static::getContainer()->get(TurnCancellations::class);
        $sessionId = 'sess-'.bin2hex(random_bytes(8));

        $this->assertFalse($cancellations->isRequested($sessionId));

        $client->request('POST', '/agent/turn/cancel', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['sessionId' => $sessionId],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertTrue($cancellations->isRequested($sessionId), 'the worker can now see the stop request');
        $cancellations->clear($sessionId);
    }

    public function testCancelWithoutSessionIdIs400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/agent/turn/cancel', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => [],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }
}
