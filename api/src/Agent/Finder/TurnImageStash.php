<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Entity\TurnImageBatch;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Binary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Hands a turn's photos from the HTTP request to the worker without putting them in the queued
 * message: a message that fails lands in the `failed` transport for good, and the photos must not.
 * The stash is short-lived and the worker forgets it once the turn settles, retried or not.
 */
final readonly class TurnImageStash
{
    private const int TTL = 900;

    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<TurnImage> $images
     */
    public function put(array $images): string
    {
        $key = 'turn_images_'.new Ulid();
        $this->write($key, $images, $this->clock->now()->modify(sprintf('+%d seconds', self::TTL)));

        return $key;
    }

    /**
     * @param list<TurnImage> $images
     */
    public function write(string $key, array $images, \DateTimeImmutable $expiresAt): void
    {
        $this->dm->createQueryBuilder(TurnImageBatch::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($key)
            ->field('images')->set(array_map(static fn (TurnImage $i): array => ['mimeType' => $i->mimeType, 'bytes' => new Binary($i->bytes)], $images))
            ->field('expiresAt')->set($expiresAt)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<TurnImage>
     */
    public function get(string $key): array
    {
        $batch = $this->dm->createQueryBuilder(TurnImageBatch::class)
            ->readOnly()
            ->field('id')->equals($key)
            ->field('expiresAt')->gt($this->clock->now())
            ->getQuery()
            ->getSingleResult();

        if (!$batch instanceof TurnImageBatch) {
            return [];
        }

        return array_map(static fn (array $i): TurnImage => new TurnImage($i['mimeType'], $i['bytes']->getData()), $batch->getImages());
    }

    public function forget(string $key): void
    {
        $this->dm->createQueryBuilder(TurnImageBatch::class)->remove()->field('id')->equals($key)->getQuery()->execute();
    }
}
