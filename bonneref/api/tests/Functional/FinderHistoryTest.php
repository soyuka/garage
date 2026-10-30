<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use Doctrine\ODM\MongoDB\DocumentManager;

final class FinderHistoryTest extends AppTestCase
{
    private const array LD = ['Accept' => 'application/ld+json'];

    public function testHidingOneEntryDropsItFromTheHistoryOnly(): void
    {
        $client = static::createClient();
        $keep = $this->seed('garder', self::TEST_OWNER, new \DateTimeImmutable('-1 hour'));
        $drop = $this->seed('retirer', self::TEST_OWNER, new \DateTimeImmutable('-2 hours'));

        $client->request('POST', '/agent/turns/'.$drop->getId().'/hide', ['headers' => self::authHeaders(), 'json' => []]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$keep->getInstruction()], $this->instructions($this->history($client)));
        self::assertEqualsCanonicalizing(['garder', 'retirer'], $this->instructions($this->journal($client)));
    }

    public function testHidingAnotherOwnersEntryIs404(): void
    {
        $client = static::createClient();
        $foreign = $this->seed('privé', 'someone-else@example.com', new \DateTimeImmutable('-1 hour'));

        $client->request('POST', '/agent/turns/'.$foreign->getId().'/hide', ['headers' => self::authHeaders(), 'json' => []]);
        self::assertResponseStatusCodeSame(404);

        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();
        $reloaded = $dm->find(AgentTurn::class, (string) $foreign->getId());
        self::assertFalse($reloaded->isHiddenFromHistory());
    }

    public function testHidingRequiresAuthentication(): void
    {
        $client = static::createAnonymousClient();
        $turn = $this->seed('x', self::TEST_OWNER, new \DateTimeImmutable('-1 hour'));

        $client->request('POST', '/agent/turns/'.$turn->getId().'/hide', ['json' => []]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(object $client): array
    {
        return $this->members($client->request('GET', '/agent/turns?surface=finder&history=1', ['headers' => self::authHeaders() + self::LD])->toArray());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(object $client): array
    {
        return $this->members($client->request('GET', '/agent/turns?surface=finder', ['headers' => self::authHeaders() + self::LD])->toArray());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function instructions(array $rows): array
    {
        return array_column($rows, 'instruction');
    }

    private function seed(string $instruction, string $owner, \DateTimeImmutable $startedAt): AgentTurn
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $turn = new AgentTurn($owner, 'sess', $instruction, 'ctx', 'anthropic', 'claude-sonnet-5-5', true);
        $turn->setSurface(TurnSurface::Finder);
        $turn->finish(AgentTurnStatus::Completed, null);
        (new \ReflectionProperty($turn, 'startedAt'))->setValue($turn, $startedAt);
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
