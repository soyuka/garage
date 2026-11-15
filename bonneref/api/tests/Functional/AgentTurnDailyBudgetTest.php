<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Agent\Journal\ModelUsage;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Entity\OwnerSpend;
use App\Repository\OwnerSpendRepository;
use App\Security\MongoSpendRecorder;
use App\Tests\Mock\FakePhotoIntakeReader;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function testTheWebSearchSideCallsCountAgainstTheBudget(): void
    {
        $client = static::createClient();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'done'));
        $this->seedTurn(self::TEST_OWNER, 0, new \DateTimeImmutable('-1 hour'), sideTokens: 12_000_000);

        $this->postTurn($client, 'sess-side');

        $this->assertResponseStatusCodeSame(429);
    }

    public function testWebSearchRequestsAreChargedPerSearch(): void
    {
        $client = static::createClient();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'done'));
        // AGENT_WEB_SEARCH_USD is $0.01 a search: 101 searches = $1.01, over the $1.00 default.
        $this->seedTurn(self::TEST_OWNER, 0, new \DateTimeImmutable('-1 hour'), webSearches: 101);

        $this->postTurn($client, 'sess-searches');

        $this->assertResponseStatusCodeSame(429);
    }

    public function testVisionIntakesShareTheBudget(): void
    {
        $client = static::createClient();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static fn (): string => 'done'));
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->persist(new OwnerSpend(self::TEST_OWNER, 'photo_intake', 'claude-haiku-5-5', new ModelUsage(promptTokens: 12_000_000), new \DateTimeImmutable('-1 hour')));
        $dm->flush();

        $this->postTurn($client, 'sess-intakes');
        $this->assertResponseStatusCodeSame(429);

        $path = (string) tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $client->request('POST', '/photo-intake', [
            'headers' => self::authHeaders() + ['content-type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($path, 'photo', 'image/png', null, true)]],
        ]);
        $this->assertResponseStatusCodeSame(429, 'an intake over the daily budget never reaches the vision model');
    }

    public function testAnIntakeReservesItsEstimateBeforeTheCallSoConcurrentOnesCannotAllPass(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $dm = static::getContainer()->get(DocumentManager::class);
        // 0.99 USD at the pinned Sonnet price: under the 1.00 budget, with room for less than one intake.
        $dm->persist(new OwnerSpend(self::TEST_OWNER, 'photo_intake', 'claude-sonnet-5-5', new ModelUsage(promptTokens: 495_000), new \DateTimeImmutable('-1 hour')));
        $dm->flush();
        $reader = static::getContainer()->get(FakePhotoIntakeReader::class);

        $this->postPhoto($client);
        $this->assertResponseStatusCodeSame(200);

        // The fake reader records no spend, as if the first call were still in flight.
        $this->postPhoto($client);
        $this->assertResponseStatusCodeSame(429);
        $this->assertSame(1, $reader->calls);
    }

    public function testTheReservationIsSettledToTheActualUsage(): void
    {
        static::createClient();
        $container = static::getContainer();
        $spend = $container->get(MongoSpendRecorder::class);

        $spend->reserve('claude-sonnet-5-5?max_tokens=2048', new ModelUsage(promptTokens: 5_000, completionTokens: 2_048));
        $spend->record('photo_intake', 'claude-sonnet-5-5?max_tokens=2048', new ModelUsage(promptTokens: 1_200, completionTokens: 300));

        $rows = $container->get(OwnerSpendRepository::class)->since(self::TEST_OWNER, new \DateTimeImmutable('-1 hour'));
        $this->assertCount(1, $rows);
        $this->assertSame(['photo_intake', 'claude-sonnet-5-5', 1_200, 300], [$rows[0]->source, $rows[0]->model, $rows[0]->promptTokens, $rows[0]->completionTokens]);
    }

    private function postPhoto(Client $client): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $client->request('POST', '/photo-intake', [
            'headers' => self::authHeaders() + ['content-type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($path, 'photo', 'image/png', null, true)]],
        ]);
    }

    private function postTurn(Client $client, string $session): void
    {
        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'filtre à huile', 'sessionId' => $session],
        ]);
    }

    private function seedTurn(string $owner, int $promptTokens, \DateTimeImmutable $startedAt, int $sideTokens = 0, int $webSearches = 0): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn($owner, 'sess', 'x', 'ctx', 'anthropic', 'claude-haiku-5-5?max_tokens=8192', true);
        $turn->addUsage($promptTokens, 0);
        if (0 !== $sideTokens || 0 !== $webSearches) {
            $turn->addSideCallUsage('claude-haiku-5-5', new ModelUsage(promptTokens: $sideTokens, webSearchRequests: $webSearches));
        }
        $turn->finish(AgentTurnStatus::Completed, null);
        $dm->persist($turn);
        $dm->flush();
        $dm->getDocumentCollection(AgentTurn::class)->updateOne(['_id' => (string) $turn->getId()], ['$set' => ['startedAt' => new UTCDateTime($startedAt)]]);
        $dm->clear();
    }
}
