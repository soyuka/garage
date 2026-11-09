<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTurn;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
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
     * both the scope and the sort, so a limited read early-cuts instead of sorting in memory.
     *
     * @return list<AgentTurn>
     */
    public function findForOwner(string $owner, int $limit = 50): array
    {
        return QueryResults::listOf(AgentTurn::class, $this->createQueryBuilder()
                ->field('owner')->equals($owner)
                ->sort('startedAt', 'desc')
                ->limit($limit)
                ->getQuery()->getIterator());
    }

    /**
     * One turn, scoped to its owner — the item read behind a tenant boundary, so a stranger's id
     * resolves to null (a 404) rather than leaking another tenant's run.
     */
    public function findOneForOwner(Ulid $id, string $owner): ?AgentTurn
    {
        return QueryResults::oneOf(AgentTurn::class, $this->createQueryBuilder()
            ->field('id')->equals((string) $id)
            ->field('owner')->equals($owner)
            ->getQuery()
            ->getSingleResult());
    }
}
