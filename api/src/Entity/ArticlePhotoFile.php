<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A proxied catalogue photo, kept for good: the id is `photo.<sha1(url)>` and an image never changes behind
 * its content-hashed URL. Written and read through {@see \App\Catalog\MongoArticlePhotoStore} only.
 */
#[ODM\Document(collection: 'article_photos')]
class ArticlePhotoFile
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    /** Null on photos migrated from the PostgreSQL pool, which only kept the hashed key. */
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $url = null;

    #[ODM\Field(type: 'string')]
    private string $mimeType;

    #[ODM\Field(type: 'bin')]
    private string $bytes;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $fetchedAt;

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }
}
