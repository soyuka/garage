<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Temporary home of turn attachments, under its own `turn-attachments` Flysystem prefix so a bucket
 * lifecycle rule or a plain `rm` can sweep it without touching bills or documents. Keys are the
 * attachment ULID, never the user's filename.
 */
final class TurnAttachmentStorage
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    public function store(string $id, string $contents): string
    {
        $this->filesystem->write($id, $contents);

        return $id;
    }

    public function read(string $key): string
    {
        return $this->filesystem->read($key);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }
}
