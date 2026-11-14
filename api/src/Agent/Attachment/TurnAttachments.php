<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use App\Entity\TurnAttachment;
use App\Message\TurnAttachmentRef;
use App\Repository\QueryResults;
use App\Storage\TurnAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Ulid;

/**
 * Owner-scoped, expiring store of the files attached to agent turns. A file is stashed at request time
 * (the worker cannot read the request body later), read back by the worker, and consumed when the agent
 * files it. Anything else expires after {@see self::TTL}: reads treat an expired row as absent at once,
 * and {@see self::pruneExpired()} frees the row and its blob.
 */
final readonly class TurnAttachments
{
    public const string TTL = '+24 hours';

    private const int OPPORTUNISTIC_PRUNE = 50;

    public function __construct(
        private DocumentManager $dm,
        private TurnAttachmentStorage $storage,
        private ClockInterface $clock,
    ) {
    }

    public function stash(string $owner, UploadedFile $file, string $mime): TurnAttachmentRef
    {
        $id = (string) new Ulid();
        $contents = (string) file_get_contents($file->getPathname());
        $key = $this->storage->store($id, $contents);

        $name = $file->getClientOriginalName();
        $attachment = new TurnAttachment($owner, $name, $mime, \strlen($contents), $key, $this->clock->now()->modify(self::TTL), $id);
        $this->dm->persist($attachment);
        $this->dm->flush();

        return new TurnAttachmentRef($id, $name, $mime, \strlen($contents));
    }

    public function find(string $owner, string $id): ?TurnAttachment
    {
        $attachment = $this->dm->getRepository(TurnAttachment::class)->findOneBy(['id' => $id, 'owner' => $owner]);

        return null !== $attachment && $attachment->getExpiresAt() > $this->clock->now() ? $attachment : null;
    }

    public function bytes(TurnAttachment $attachment): string
    {
        return $this->storage->read($attachment->getStorageKey());
    }

    public function consume(TurnAttachment $attachment): void
    {
        $this->dm->remove($attachment);
        $this->dm->flush();
        $this->storage->delete($attachment->getStorageKey());
    }

    /** Deletes expired rows and their blobs; returns how many. Bounded so an upload never pays for a backlog. */
    public function pruneExpired(int $limit = self::OPPORTUNISTIC_PRUNE): int
    {
        $expired = QueryResults::listOf(TurnAttachment::class, $this->dm->createQueryBuilder(TurnAttachment::class)
            ->field('expiresAt')->lte($this->clock->now())
            ->limit($limit)
            ->getQuery()
            ->getIterator());

        $count = 0;
        foreach ($expired as $attachment) {
            $this->dm->remove($attachment);
            $this->storage->delete($attachment->getStorageKey());
            ++$count;
        }
        $this->dm->flush();

        return $count;
    }
}
