<?php

declare(strict_types=1);

namespace App\Mercure;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleFact;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a Mercure update for every garage domain write, so open dashboards live-patch.
 *
 * Sits at the persistence layer (not in the MCP processors) so it catches every write path —
 * the log_maintenance / add_vehicle_fact tools today, future HTTP operations and fixtures
 * tomorrow — from one place. Changes are buffered through the flush (postPersist/postUpdate)
 * and published once it commits (postFlush): publishing is a synchronous POST to the hub, so it
 * must run AFTER the transaction, never inside it.
 *
 * The topic is the vehicle's canonical IRI built with ABS_URL *per call* — NOT by flipping the
 * global url_generation_strategy, which would turn every MCP `@id` absolute and break the
 * relative-IRI contract the tools and tests rely on. The host therefore comes from the same
 * source as every other IRI (the request, or routing default_uri in a CLI/worker context), so
 * there is no second domain definition to keep in sync. The frontend, holding a relative `@id`,
 * subscribes to `location.origin + @id`.
 *
 * Two topics per change:
 *   - the item topic  ({origin}/garage/vehicles/{ulid}) — the detail / maintenance / facts panels;
 *   - the collection topic ({origin}/garage/vehicles)    — the sidebar parc list.
 *
 * Later: move the publish to a Messenger handler so the request does not wait on the hub.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postFlush)]
final class DomainEventPublisher
{
    /** @var array<string, Update> buffered updates, deduped by topic+payload within a flush */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly IriConverterInterface $iriConverter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->capture($args->getObject(), 'created');
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->capture($args->getObject(), 'updated');
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $updates = $this->pending;
        $this->pending = [];

        // The write already committed; a live-update is best-effort. A hub that is down (or absent,
        // as in tests) must never turn a successful domain write into a failure — log and move on.
        foreach ($updates as $update) {
            try {
                $this->hub->publish($update);
            } catch (\Throwable $e) {
                $this->logger->warning('Mercure publish failed for topic {topic}: {error}', [
                    'topic' => $update->getTopics()[0] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function capture(object $entity, string $event): void
    {
        [$vehicleId, $kind] = match (true) {
            $entity instanceof Vehicle => [$entity->getId(), 'vehicle'],
            $entity instanceof MaintenanceRecord => [$entity->getVehicle()->getId(), 'maintenance'],
            $entity instanceof PlannedMaintenance => [$entity->getVehicle()->getId(), 'planned'],
            $entity instanceof VehicleFact => [$entity->getVehicle()->getId(), 'fact'],
            default => [null, null],
        };

        if (null === $vehicleId) {
            return;
        }

        $path = '/garage/vehicles/'.$vehicleId;
        $itemTopic = $this->iriConverter->getIriFromResource(
            GarageVehicle::class,
            UrlGeneratorInterface::ABS_URL,
            context: ['uri_variables' => ['id' => (string) $vehicleId]],
        );
        $collectionTopic = substr($itemTopic, 0, (int) strrpos($itemTopic, '/'));

        $payload = json_encode([
            'kind' => $kind,
            'event' => $event,
            'vehicle' => (string) $vehicleId,
            'iri' => $path,
        ], \JSON_THROW_ON_ERROR);

        $this->pending[$itemTopic.'|'.$payload] = new Update($itemTopic, $payload);
        $this->pending[$collectionTopic.'|'.$payload] = new Update($collectionTopic, $payload);
    }
}
