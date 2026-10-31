<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Keys are `{vehicleId}/{documentId}` (both ULIDs) under the `vehicle-documents` Flysystem prefix —
 * opaque, never derived from the user's filename.
 */
final class VehicleDocumentStorage
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    /**
     * @param resource $stream
     */
    public function store(string $vehicleId, string $documentId, $stream): string
    {
        $key = \sprintf('%s/%s', $vehicleId, $documentId);
        $this->filesystem->writeStream($key, $stream);

        return $key;
    }

    /** @return resource */
    public function readStream(string $key)
    {
        return $this->filesystem->readStream($key);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }
}
