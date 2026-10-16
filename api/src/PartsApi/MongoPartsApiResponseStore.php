<?php

declare(strict_types=1);

namespace App\PartsApi;

use App\Entity\PartsApiResponse;
use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Psr\Clock\ClockInterface;

/**
 * Reads hydrate read-only and writes are single upserts, so a response never enters the unit of work: the
 * FrankenPHP and messenger workers are long-lived and must not accumulate half-megabyte catalogue blobs in
 * the identity map, nor have a domain flush walk them.
 */
final readonly class MongoPartsApiResponseStore implements PartsApiResponseStore
{
    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $key): ?array
    {
        $response = $this->live()->field('id')->equals($key)->getQuery()->getSingleResult();

        return $response instanceof PartsApiResponse ? $response->getData() : null;
    }

    public function findMany(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        $hits = [];
        foreach ($this->live()->field('id')->in(array_values($keys))->getQuery()->execute() as $response) {
            \assert($response instanceof PartsApiResponse);
            $hits[$response->getId()] = $response->getData();
        }

        return $hits;
    }

    public function save(string $key, string $method, array $params, array $data, ?int $ttlSeconds): void
    {
        $now = $this->clock->now();
        $this->write($key, $method, $params, $data, $now, null === $ttlSeconds ? null : $now->modify(sprintf('+%d seconds', $ttlSeconds)));
    }

    /**
     * @param array<string, int|string>|null $params
     * @param array<mixed>                   $data
     */
    public function write(string $key, string $method, ?array $params, array $data, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $expiresAt): void
    {
        $payload = BsonPayload::encode($data);

        $this->dm->createQueryBuilder(PartsApiResponse::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($key)
            ->field('method')->set($method)
            ->field('params')->set($params)
            ->field('data')->set($payload['native'])
            ->field('dataJson')->set($payload['json'])
            ->field('fetchedAt')->set($fetchedAt)
            ->field('expiresAt')->set($expiresAt)
            ->getQuery()
            ->execute();
    }

    private function live(): Builder
    {
        $builder = $this->dm->createQueryBuilder(PartsApiResponse::class)->readOnly();
        $builder->addOr($builder->expr()->field('expiresAt')->equals(null));
        $builder->addOr($builder->expr()->field('expiresAt')->gt($this->clock->now()));

        return $builder;
    }
}
