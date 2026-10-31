<?php

declare(strict_types=1);

namespace App\Repository;

use App\Agent\TurnSurface;
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
                ->getIterator()
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
}
