<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VehicleFact;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

/**
 * @extends ServiceDocumentRepository<VehicleFact>
 */
final class VehicleFactRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VehicleFact::class);
    }
}
