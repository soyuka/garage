<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;

final class AgentTurnDailyBudgetTest extends AppTestCase
{
    public function testATurnOverTheOwnersDailyBudgetIs429AndNeverStarts(): void
    {
        $client = static::createClient();
        $invocations = 0;
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use (&$invocations): string {
            ++$invocations;

            return 'done';
        }));
        // claude-haiku-5-5 input is $0.10/MTok in .env.test: 6M + 6M prompt tokens = $1.20, over the $1.00 default.
        $this->seedTurn(self::TEST_OWNER, 6_000_000, new \DateTimeImmutable('-2 hours'));
        $this->seedTurn(self::TEST_OWNER, 6_000_000, new \DateTimeImmutable('-20 minutes'));

        $response = $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-budget'],
        ]);

        $this->assertResponseStatusCodeSame(429);
        $this->assertStringContainsString('budget', $response->getContent(false));
        $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
        $this->assertGreaterThan(21 * 3600, $retryAfter, 'the oldest turn of the window leaves it in about 22 hours');
        $this->assertLessThanOrEqual(22 * 3600, $retryAfter);
        $this->assertSame(0, $invocations);
    }

    public function testOnlyTheLastTwentyFourHoursAndTheOwnersOwnTurnsCount(): void
    {
        $client = static::createClient();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'done'));
        $this->seedTurn(self::TEST_OWNER, 6_000_000, new \DateTimeImmutable('-25 hours'));
        $this->seedTurn(self::TEST_OWNER, 6_000_000, new \DateTimeImmutable('-1 hour'));
        $this->seedTurn('someone-else@example.com', 20_000_000, new \DateTimeImmutable('-1 hour'));

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => 'sess-budget-ok'],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    private function seedTurn(string $owner, int $promptTokens, \DateTimeImmutable $startedAt): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn($owner, 'sess', 'x', 'ctx', 'anthropic', 'claude-haiku-5-5?max_tokens=8192', true);
        $turn->addUsage($promptTokens, 0);
        $turn->finish(AgentTurnStatus::Completed, null);
        $dm->persist($turn);
        $dm->flush();
        $dm->getDocumentCollection(AgentTurn::class)->updateOne(['_id' => (string) $turn->getId()], ['$set' => ['startedAt' => new UTCDateTime($startedAt)]]);
        $dm->clear();
    }
}
