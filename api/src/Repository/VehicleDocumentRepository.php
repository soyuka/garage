<?php

declare(strict_types=1);

namespace App\Repository;

use App\Documentation\CorpusDocument;
use App\Documentation\VehicleDocumentKind;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Security\OwnerContext;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Doctrine\ODM\MongoDB\Query\Builder;
use MongoDB\BSON\UTCDateTime;

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

        return QueryResults::listOf(VehicleDocument::class, $qb->getQuery()->getIterator());
    }

    /**
     * Streams the searchable text without hydrating or managing documents: a vehicle's manuals are
     * megabytes of page text, and the unit of work would hold a snapshot of each next to the objects.
     *
     * @return \Generator<int, CorpusDocument>
     */
    public function searchCorpus(Vehicle $vehicle, ?VehicleDocumentKind $kind = null): \Generator
    {
        $qb = $this->forVehicle($vehicle)
            ->hydrate(false)
            ->select('title', 'kind', 'sourceRef', 'addedAt', 'pages.number', 'pages.label', 'pages.text')
            ->sort('addedAt', 'desc')
            ->sort('id', 'desc');

        if (null !== $kind) {
            $qb->field('kind')->equals($kind->value);
        }

        $cursor = $qb->getQuery()->execute();
        if (!is_iterable($cursor)) {
            throw new \UnexpectedValueException('The documentation corpus query did not return a cursor.');
        }

        foreach ($cursor as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            $pages = [];
            $rawPages = $raw['pages'] ?? [];
            foreach (\is_array($rawPages) ? $rawPages : [] as $page) {
                if (!\is_array($page)) {
                    continue;
                }
                $label = $page['label'] ?? null;
                $pages[] = [
                    'number' => \is_int($page['number'] ?? null) ? $page['number'] : 0,
                    'label' => \is_string($label) ? $label : null,
                    'text' => \is_string($page['text'] ?? null) ? $page['text'] : '',
                ];
            }

            $addedAt = $raw['addedAt'] ?? null;
            $sourceRef = $raw['sourceRef'] ?? null;

            yield new CorpusDocument(
                id: \is_string($raw['_id'] ?? null) ? $raw['_id'] : '',
                title: \is_string($raw['title'] ?? null) ? $raw['title'] : '',
                kind: VehicleDocumentKind::from(\is_string($raw['kind'] ?? null) ? $raw['kind'] : ''),
                sourceRef: \is_string($sourceRef) ? $sourceRef : null,
                addedAt: $addedAt instanceof UTCDateTime ? \DateTimeImmutable::createFromMutable($addedAt->toDateTime()) : new \DateTimeImmutable('@0'),
                pages: $pages,
            );
        }
    }

    public function findOneForVehicle(Vehicle $vehicle, string $id): ?VehicleDocument
    {
        return QueryResults::oneOf(VehicleDocument::class, $this->forVehicle($vehicle)->field('id')->equals($id)->getQuery()->getSingleResult());
    }

    public function findBySourceRef(Vehicle $vehicle, string $sourceRef, string $filename): ?VehicleDocument
    {
        return QueryResults::oneOf(VehicleDocument::class, $this->forVehicle($vehicle)
            ->field('sourceRef')->equals($sourceRef)
            ->field('filename')->equals($filename)
            ->getQuery()->getSingleResult());
    }

    private function forVehicle(Vehicle $vehicle): Builder
    {
        return $this->createQueryBuilder()
            ->field('owner')->equals($this->owner->current())
            ->field('vehicle')->references($vehicle);
    }
}
