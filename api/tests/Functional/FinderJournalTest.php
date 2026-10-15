<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnKind;
use App\Entity\AgentTurnStatus;
use App\Repository\AgentTurnRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

final class FinderJournalTest extends AppTestCase
{
    public function testTheListingFiltersBySurface(): void
    {
        $client = static::createClient();
        $this->seed('filtre à huile', TurnSurface::Finder);
        $this->seed('vidange', TurnSurface::Garage);

        $finder = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        self::assertSame(['filtre à huile'], array_column($finder, 'instruction'));
        self::assertSame('finder', $finder[0]['surface']);
        self::assertSame('agent', $finder[0]['kind']);

        $garage = $this->members($client->request('GET', '/agent/turns?surface=garage', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        self::assertSame(['vidange'], array_column($garage, 'instruction'));

        $all = $this->members($client->request('GET', '/agent/turns', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        self::assertCount(2, $all);
    }

    public function testTheTurnExposesCacheTokens(): void
    {
        $client = static::createClient();
        $turn = $this->seed('filtre à huile', TurnSurface::Finder, static fn (AgentTurn $t) => $t->addUsage(8, 250, 12000, 3000));

        $summary = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray())[0];
        self::assertSame(15258, $summary['totalTokens']);
        self::assertSame(12000, $summary['cacheReadTokens']);
        self::assertSame(3000, $summary['cacheCreationTokens']);

        $item = $client->request('GET', '/agent/turns/'.$turn->getId(), ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray();
        self::assertSame(8, $item['promptTokens']);
        self::assertSame(250, $item['completionTokens']);
        self::assertSame(12000, $item['cacheReadTokens']);
        self::assertSame(3000, $item['cacheCreationTokens']);
        self::assertNull($item['estimatedCostUsd'] ?? null, 'no configured price, no cost');
    }

    public function testAnAuthenticatedFinderSearchIsJournaled(): void
    {
        $client = static::createClient();

        $narrowed = $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'X-Finder-Rule' => 'category', 'X-Finder-Input' => 'filtre à huile'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(AgentTurnKind::Search, $row->getKind());
        self::assertSame(TurnSurface::Finder, $row->getSurface());
        self::assertSame(AgentTurnStatus::Completed, $row->getStatus());
        self::assertSame('filtre à huile', $row->getInstruction());
        self::assertSame($narrowed['upstreamCalls'], $row->getPartsApiCalls());
        self::assertNotNull($row->getDurationMs());
        self::assertSame([
            'rule' => 'category',
            'carId' => 19107,
            'categoryId' => 100259,
            'clusterCount' => \count($narrowed['clusters']),
            'totalArticles' => $narrowed['totalArticles'],
            'unclassifiedCount' => $narrowed['unclassifiedCount'],
            'outcome' => $narrowed['narrowed'] ? 'ok' : 'degraded',
            'httpStatus' => 200,
        ], $row->getSearch());

        $listed = $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']])->toArray());
        self::assertSame('search', $listed[0]['kind']);
        self::assertSame($narrowed['upstreamCalls'], $listed[0]['partsApiCalls']);
        self::assertSame('category', $listed[0]['search']['rule']);
    }

    public function testAPlateLookupIsJournaledWithItsOutcome(): void
    {
        $client = static::createClient();

        $client->request('GET', '/vehicles/ZZ999ZZ', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json', 'X-Finder-Rule' => 'plate', 'X-Finder-Input' => 'zz 999 zz']]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(AgentTurnStatus::Failed, $rows[0]->getStatus());
        self::assertSame('plate', $rows[0]->getSearch()['rule']);
        self::assertSame('not_found', $rows[0]->getSearch()['outcome']);
        self::assertSame(404, $rows[0]->getSearch()['httpStatus']);
    }

    public function testAnonymousSearchesAreNotJournaled(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', [
            'headers' => ['Accept' => 'application/ld+json', 'X-Finder-Rule' => 'category', 'X-Finder-Input' => 'filtre à huile'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->rows());
    }

    public function testSearchesFromOtherClientsAreNotJournaled(): void
    {
        $client = static::createClient();

        $client->request('GET', '/cars/19107/part-categories/100259/narrowed?carType=PC&make=MAZDA', ['headers' => self::authHeaders() + ['Accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->rows());
    }

    /**
     * @return list<AgentTurn>
     */
    private function rows(): array
    {
        static::getContainer()->get(DocumentManager::class)->clear();

        return static::getContainer()->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
    }

    /**
     * @param (\Closure(AgentTurn): void)|null $with
     */
    private function seed(string $instruction, TurnSurface $surface, ?\Closure $with = null): AgentTurn
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn(self::TEST_OWNER, 'sess', $instruction, 'ctx', 'anthropic', 'claude-sonnet-5-5', true);
        $turn->setSurface($surface);
        if (null !== $with) {
            $with($turn);
        }
        $turn->finish(AgentTurnStatus::Completed, null);
        $dm->persist($turn);
        $dm->flush();

        return $turn;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $data): array
    {
        return $data['member'] ?? $data['hydra:member'] ?? [];
    }
}
