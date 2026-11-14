<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A file attached to an in-app agent turn, kept only until the agent files it or `expiresAt` passes.
 * The bytes live on the turn-attachment Flysystem store under `storageKey`; this row is the owner-scoped
 * index. Deliberately NOT a Mongo TTL index: the TTL monitor would delete the row and orphan the blob, so
 * {@see \App\Agent\Attachment\TurnAttachments::pruneExpired()} removes both.
 */
#[ODM\Document(collection: 'turn_attachments')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'idx_turn_attachment_expires')]
class TurnAttachment
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $owner,
        #[ODM\Field(type: 'string')]
        private string $name,
        #[ODM\Field(type: 'string')]
        private string $contentType,
        #[ODM\Field(type: 'int')]
        private int $size,
        #[ODM\Field(type: 'string')]
        private string $storageKey,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $expiresAt,
        string $id = '',
    ) {
        $this->id = '' !== $id ? $id : (string) new Ulid();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
