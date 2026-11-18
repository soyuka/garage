<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mercure\DomainPublisher;
use App\Entity\MaintenanceAttachment;
use App\Repository\MaintenanceRecordRepository;
use App\Storage\MaintenanceAttachmentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * DELETE /garage/maintenance-records/{id} — remove a logged visit by hand. Owner-gated through the
 * record's vehicle (a foreign or soft-deleted vehicle's record is a 404). A hard delete: history is
 * a manual log here, not an audit trail. Fans the vehicle out over Mercure so the rail refreshes.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MaintenanceRecordDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private MaintenanceRecordRepository $records,
        private DocumentManager $em,
        private DomainPublisher $publisher,
        private MaintenanceAttachmentStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        $record = $this->records->findOwned($id instanceof Ulid ? $id : Ulid::fromString((string) $id));

        if (null === $record) {
            throw new NotFoundHttpException('No maintenance record at this IRI.');
        }

        $vehicle = $record->getVehicle();
        $keys = array_map(static fn (MaintenanceAttachment $attachment): string => $attachment->getStorageKey(), $record->getAttachments());
        // Metadata first, as for one bill: a failed flush keeps a visit whose bills still download.
        $this->em->remove($record);
        $this->em->flush();
        foreach ($keys as $key) {
            try {
                $this->storage->delete($key);
            } catch (FilesystemException $e) {
                $this->logger->warning('Orphaned bill blob after visit deletion', ['key' => $key, 'exception' => $e]);
            }
        }
        $this->publisher->publishVehicle($vehicle);

        return null;
    }
}
