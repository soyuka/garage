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

        $this->assertFalse($cancellations->isRequested('sess-xyz'));

        $client->request('POST', '/agent/turn/cancel', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['sessionId' => 'sess-xyz'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertTrue($cancellations->isRequested('sess-xyz'), 'the worker can now see the stop request');
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
