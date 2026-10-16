<?php

declare(strict_types=1);

namespace App\Motul;

use App\Entity\MotulResponse;
use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;

final readonly class MongoMotulResponseStore implements MotulResponseStore
{
    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $key): ?array
    {
        $response = $this->dm->createQueryBuilder(MotulResponse::class)->readOnly()->field('id')->equals($key)->getQuery()->getSingleResult();

        return $response instanceof MotulResponse ? $response->getData() : null;
    }

    public function save(string $key, array $data): void
    {
        $this->write($key, $data, $this->clock->now());
    }

    /**
     * @param array<mixed> $data
     */
    public function write(string $key, array $data, \DateTimeImmutable $fetchedAt): void
    {
        $payload = BsonPayload::encode($data);

        $this->dm->createQueryBuilder(MotulResponse::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($key)
            ->field('data')->set($payload['native'])
            ->field('dataJson')->set($payload['json'])
            ->field('fetchedAt')->set($fetchedAt)
            ->getQuery()
            ->execute();
    }
}
