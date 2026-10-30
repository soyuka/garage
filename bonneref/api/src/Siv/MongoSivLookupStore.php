<?php

declare(strict_types=1);

namespace App\Siv;

use App\Entity\SivLookup;
use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;

final readonly class MongoSivLookupStore implements SivLookupStore
{
    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $plate): ?array
    {
        $lookup = $this->dm->createQueryBuilder(SivLookup::class)->readOnly()->field('id')->equals($plate)->getQuery()->getSingleResult();

        return $lookup instanceof SivLookup ? $lookup->getEnvelope() : null;
    }

    public function save(string $plate, array $envelope): void
    {
        $this->write($plate, $envelope, $this->clock->now());
    }

    /**
     * @param array<mixed> $envelope
     */
    public function write(string $plate, array $envelope, \DateTimeImmutable $fetchedAt): void
    {
        $payload = BsonPayload::encode($envelope);

        $this->dm->createQueryBuilder(SivLookup::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($plate)
            ->field('envelope')->set($payload['native'])
            ->field('envelopeJson')->set($payload['json'])
            ->field('fetchedAt')->set($fetchedAt)
            ->getQuery()
            ->execute();
    }
}
