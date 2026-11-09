<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MakeKnowledge;
use App\Knowledge\MakeName;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

/**
 * Deliberately not owner-scoped: make knowledge is global reference data, see {@see MakeKnowledge}.
 *
 * @extends ServiceDocumentRepository<MakeKnowledge>
 */
class MakeKnowledgeRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MakeKnowledge::class);
    }

    /**
     * @return list<MakeKnowledge>
     */
    public function findForMake(string $make): array
    {
        return QueryResults::listOf(MakeKnowledge::class, $this->createQueryBuilder()
            ->field('makes')->equals(MakeName::normalize($make))
            ->sort('topic', 'asc')
            ->getQuery()->getIterator());
    }

    public function findOneForMake(string $make, string $topic): ?MakeKnowledge
    {
        return QueryResults::oneOf(MakeKnowledge::class, $this->createQueryBuilder()
            ->field('makes')->equals(MakeName::normalize($make))
            ->field('topic')->equals($topic)
            ->getQuery()->getSingleResult());
    }

    /**
     * @return list<MakeKnowledge>
     */
    public function findEvery(): array
    {
        return $this->findAll();
    }
}
