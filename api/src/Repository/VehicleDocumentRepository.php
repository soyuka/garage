<?php

declare(strict_types=1);

namespace App\Repository;

use App\Documentation\VehicleDocumentKind;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Security\OwnerContext;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Doctrine\ODM\MongoDB\Query\Builder;

/**
 * @extends ServiceDocumentRepository<VehicleDocument>
 */
final class VehicleDocumentRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry, private readonly OwnerContext $owner)
    {
        parent::__construct($registry, VehicleDocument::class);
    }

    /**
     * @return list<VehicleDocument>
     */
    public function findForVehicle(Vehicle $vehicle, ?VehicleDocumentKind $kind = null): array
    {
        $qb = $this->forVehicle($vehicle)->sort('addedAt', 'desc')->sort('id', 'desc');

        if (null !== $kind) {
            $qb->field('kind')->equals($kind->value);
        }

        return array_values($qb->getQuery()->execute()->toArray());
    }

    public function findOneForVehicle(Vehicle $vehicle, string $id): ?VehicleDocument
    {
        return $this->forVehicle($vehicle)->field('id')->equals($id)->getQuery()->getSingleResult();
    }

    public function findBySourceRef(Vehicle $vehicle, string $sourceRef, string $filename): ?VehicleDocument
    {
        return $this->forVehicle($vehicle)
            ->field('sourceRef')->equals($sourceRef)
            ->field('filename')->equals($filename)
            ->getQuery()->getSingleResult();
    }

    private function forVehicle(Vehicle $vehicle): Builder
    {
        return $this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('vehicle')->references($vehicle);
    }
}
