<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Entity\VehicleDocumentPage;
use App\Mercure\DomainPublisher;
use App\Repository\VehicleDocumentRepository;
use App\Storage\VehicleDocumentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Ulid;

/**
 * The single write path for vehicle documentation, shared by the upload controller and the import
 * command. A document with the same (vehicle, sourceRef, filename) is replaced in place — same id,
 * same storage key — so re-importing a source never duplicates it.
 */
final readonly class VehicleDocumentRecorder
{
    public const int MAX_BYTES = 50 * 1024 * 1024;

    private const array ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private VehicleDocumentStorage $storage,
        private VehicleDocumentRepository $documents,
        private PdfTextExtractor $extractor,
        private DocumentManager $em,
        private DomainPublisher $publisher,
    ) {
    }

    public function record(
        Vehicle $vehicle,
        string $path,
        string $filename,
        VehicleDocumentKind $kind,
        VehicleDocumentSource $source = VehicleDocumentSource::Upload,
        ?string $title = null,
        ?string $sourceRef = null,
        ?string $plannedTaskId = null,
    ): VehicleDocument {
        $size = (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new InvalidVehicleDocumentException(sprintf('File too large (%d bytes); the limit is %d bytes.', $size, self::MAX_BYTES));
        }

        $mime = (string) MimeTypes::getDefault()->guessMimeType($path);
        if (!\in_array($mime, self::ALLOWED_TYPES, true)) {
            throw new InvalidVehicleDocumentException(sprintf('Unsupported file type "%s"; allowed: PDF, JPEG, PNG.', $mime));
        }

        if (null !== $plannedTaskId) {
            $task = $this->em->find(PlannedMaintenance::class, $plannedTaskId);
            if (null === $task || !$task->getVehicle()->getId()->equals($vehicle->getId())) {
                throw new InvalidVehicleDocumentException('plannedTaskId does not belong to this vehicle.');
            }
        }

        $pages = 'application/pdf' === $mime
            ? array_map(
                static fn (array $page): VehicleDocumentPage => new VehicleDocumentPage($page['number'], $page['text'], $page['label']),
                $this->extractor->extract($path),
            )
            : [];

        $title = null !== $title && '' !== trim($title) ? trim($title) : $filename;
        $existing = null !== $sourceRef ? $this->documents->findBySourceRef($vehicle, $sourceRef, $filename) : null;
        $id = $existing?->getId() ?? (string) new Ulid();

        $stream = fopen($path, 'rb');
        try {
            $key = $this->storage->store((string) $vehicle->getId(), $id, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        if (null !== $existing) {
            $existing->replaceContent($kind, $title, $filename, $mime, $size, $plannedTaskId, $pages);
            $document = $existing;
        } else {
            $document = new VehicleDocument($id, $vehicle, $kind, $title, $source, $filename, $mime, $size, $key, sourceRef: $sourceRef, plannedTaskId: $plannedTaskId, owner: $vehicle->getOwner(), pages: $pages);
            $this->em->persist($document);
        }

        $this->em->flush();
        $this->publisher->publishVehicle($vehicle);

        return $document;
    }

    public function remove(VehicleDocument $document): void
    {
        $this->storage->delete($document->getStorageKey());
        $this->em->remove($document);
        $this->em->flush();
        $this->publisher->publishVehicle($document->getVehicle());
    }
}
