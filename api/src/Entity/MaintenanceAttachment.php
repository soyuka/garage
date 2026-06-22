<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * One bill (PDF/JPEG/PNG) attached to a {@see MaintenanceRecord}. The binary lives in the S3-like
 * object store keyed by {@see $storageKey}; this embedded document holds only the metadata needed
 * to list, address and serve it. A visit can carry several — one per bill/ticket.
 */
#[ODM\EmbeddedDocument]
class MaintenanceAttachment
{
    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $id,
        // The user-facing original name, kept for display + the download filename.
        #[ODM\Field(type: 'string')]
        private string $filename,
        #[ODM\Field(type: 'string')]
        private string $contentType,
        #[ODM\Field(type: 'int')]
        private int $size,
        // The object key in the store — opaque, never derived from the filename (collisions, traversal).
        #[ODM\Field(type: 'string')]
        private string $storageKey,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $uploadedAt = new \DateTimeImmutable(),
    ) {
    }

    public static function create(string $filename, string $contentType, int $size, string $storageKey): self
    {
        return new self((string) new Ulid(), $filename, $contentType, $size, $storageKey);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
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

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
