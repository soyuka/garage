<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnCancellations;
use App\Agent\Ui\TurnSessionTopic;

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
        $key = static::getContainer()->get(TurnSessionTopic::class)->key(self::TEST_OWNER, $sessionId);

        $this->assertFalse($cancellations->isRequested($key));

        $client->request('POST', '/agent/turn/cancel', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['sessionId' => $sessionId],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertTrue($cancellations->isRequested($key), 'the worker can now see the stop request');
        $cancellations->clear($key);
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
