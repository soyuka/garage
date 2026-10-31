<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * The seam between maintenance bills and the object store. Keys are `{recordId}/{attachmentId}`
 * (both ULIDs) under the Flysystem prefix — opaque, never derived from the user's filename, so a
 * hostile name can neither collide nor traverse. The store backend is configured per environment
 * (local files by default, S3 when STORAGE_ADAPTER=s3); this class is agnostic to which.
 */
final class MaintenanceAttachmentStorage
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    /** Persist a bill's bytes; returns the storage key to record on the attachment. */
    public function store(string $recordId, string $attachmentId, string $contents): string
    {
        $key = $this->key($recordId, $attachmentId);
        $this->filesystem->write($key, $contents);

        return $key;
    }

    /** @return resource a read stream for the stored bytes */
    public function readStream(string $key)
    {
        return $this->filesystem->readStream($key);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    private function key(string $recordId, string $attachmentId): string
    {
        return sprintf('%s/%s', $recordId, $attachmentId);
    }
}
