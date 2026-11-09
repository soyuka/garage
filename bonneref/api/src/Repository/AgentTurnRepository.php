<?php

declare(strict_types=1);

namespace App\Repository;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceDocumentRepository<AgentTurn>
 */
final class AgentTurnRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTurn::class);
    }

    /**
     * The journal listing: an owner's turns, newest first. The {owner, startedAt desc} index covers
     * both the scope and the sort, so a limited read early-cuts instead of sorting in memory. With
     * $history, the finder home's view: rows the owner hid are left out (the journal drawer
     * never passes it, so it stays complete).
     *
     * @return list<AgentTurn>
     */
    public function findForOwner(string $owner, int $limit = 50, ?TurnSurface $surface = null, bool $history = false): array
    {
        $qb = $this->createQueryBuilder()->field('owner')->equals($owner);
        if (null !== $surface) {
            $qb->field('surface')->equals($surface->value);
        }

        if ($history) {
            // $ne (not equals(false)): rows written before the flag existed carry no such field.
            $qb->field('hiddenFromHistory')->notEqual(true);
        }

        return array_values(
            $qb
                ->sort('startedAt', 'desc')
                ->limit($limit)
                ->getQuery()
                ->toArray()
        );
    }

    /**
     * One turn, scoped to its owner — the item read behind a tenant boundary, so a stranger's id
     * resolves to null (a 404) rather than leaking another tenant's run.
     */
    public function findOneForOwner(Ulid $id, string $owner): ?AgentTurn
    {
        return $this->createQueryBuilder()
            ->field('id')->equals((string) $id)
            ->field('owner')->equals($owner)
            ->getQuery()
            ->getSingleResult();
    }

    public function findOneBySearchId(string $owner, string $searchId): ?AgentTurn
    {
        return $this->createQueryBuilder()
            ->field('owner')->equals($owner)
            ->field('searchId')->equals($searchId)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * The cost-bearing fields of an owner's turns since a date, oldest first, read raw (no hydration of
     * the tool calls and raw payloads a journal row carries).
     *
     * @return list<array{startedAt: \DateTimeImmutable, model: string, promptTokens: ?int, completionTokens: ?int, cacheReadTokens: ?int, cacheCreationTokens: ?int, sideCalls: array<string, array{promptTokens: int, completionTokens: int, cacheReadTokens: int, cacheCreationTokens: int}>, webSearchRequests: int}>
     */
    public function usageSince(string $owner, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder()
            ->hydrate(false)
            ->select('startedAt', 'model', 'promptTokens', 'completionTokens', 'cacheReadTokens', 'cacheCreationTokens', 'sideCallUsage', 'webSearchRequests')
            ->field('owner')->equals($owner)
            ->field('startedAt')->gte($since)
            ->sort('startedAt', 'asc')
            ->getQuery()
            ->toArray();

        $int = static fn (mixed $v): ?int => \is_int($v) ? $v : null;

        return array_values(array_map(static fn (array $row): array => [
            'startedAt' => $row['startedAt'] instanceof UTCDateTime ? \DateTimeImmutable::createFromInterface($row['startedAt']->toDateTime()) : $since,
            'model' => \is_string($row['model'] ?? null) ? $row['model'] : '',
            'promptTokens' => $int($row['promptTokens'] ?? null),
            'completionTokens' => $int($row['completionTokens'] ?? null),
            'cacheReadTokens' => $int($row['cacheReadTokens'] ?? null),
            'cacheCreationTokens' => $int($row['cacheCreationTokens'] ?? null),
            'sideCalls' => self::sideCalls($row['sideCallUsage'] ?? null),
            'webSearchRequests' => $int($row['webSearchRequests'] ?? null) ?? 0,
        ], $rows));
    }

    /**
     * @return array<string, array{promptTokens: int, completionTokens: int, cacheReadTokens: int, cacheCreationTokens: int}>
     */
    private static function sideCalls(mixed $raw): array
    {
        $calls = [];
        foreach (\is_array($raw) || $raw instanceof \Traversable ? $raw : [] as $model => $tokens) {
            $tokens = (array) $tokens;
            $calls[(string) $model] = [
                'promptTokens' => (int) ($tokens['promptTokens'] ?? 0),
                'completionTokens' => (int) ($tokens['completionTokens'] ?? 0),
                'cacheReadTokens' => (int) ($tokens['cacheReadTokens'] ?? 0),
                'cacheCreationTokens' => (int) ($tokens['cacheCreationTokens'] ?? 0),
            ];
        }

        return $calls;
    }
}
