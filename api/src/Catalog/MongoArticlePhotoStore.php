<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\ArticlePhotoFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;

final readonly class MongoArticlePhotoStore implements ArticlePhotoStore
{
    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public static function id(string $url): string
    {
        return 'photo.'.sha1($url);
    }

    public function find(string $url): ?ArticlePhoto
    {
        $file = $this->dm->createQueryBuilder(ArticlePhotoFile::class)->readOnly()->field('id')->equals(self::id($url))->getQuery()->getSingleResult();

        return $file instanceof ArticlePhotoFile ? new ArticlePhoto($file->getMimeType(), $file->getBytes()) : null;
    }

    public function save(string $url, ArticlePhoto $photo): void
    {
        $this->write(self::id($url), $url, $photo, $this->clock->now());
    }

    public function write(string $id, ?string $url, ArticlePhoto $photo, \DateTimeImmutable $fetchedAt): void
    {
        $this->dm->createQueryBuilder(ArticlePhotoFile::class)
            ->updateOne()
            ->upsert()
            ->field('id')->equals($id)
            ->field('url')->set($url)
            ->field('mimeType')->set($photo->contentType)
            ->field('bytes')->set($photo->body)
            ->field('fetchedAt')->set($fetchedAt)
            ->getQuery()
            ->execute();
    }
}
