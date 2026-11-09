<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OwnerSpend;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

/**
 * @extends ServiceDocumentRepository<OwnerSpend>
 */
final class OwnerSpendRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OwnerSpend::class);
    }

    /**
     * @return list<OwnerSpend> oldest first
     */
    public function since(string $owner, \DateTimeImmutable $since): array
    {
        /** @var list<OwnerSpend> $rows */
        $rows = array_values($this->createQueryBuilder()
            ->readOnly()
            ->field('owner')->equals($owner)
            ->field('at')->gte($since)
            ->sort('at', 'asc')
            ->getQuery()
            ->toArray());

        return $rows;
    }
}
