<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
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
