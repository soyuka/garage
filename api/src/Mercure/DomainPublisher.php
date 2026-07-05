<?php

declare(strict_types=1);

namespace App\Mercure;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use App\ApiResource\GarageVehicle;
use App\Entity\Vehicle;
use App\Garage\GarageVehicleView;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes the full vehicle read-model to the dashboard on a write, called explicitly from the write
 * processors after they flush. Replaces the implicit Doctrine postFlush listener (DomainEventPublisher):
 * a write path now opts in by calling {@see self::publishVehicle()}, and the payload CARRIES the
 * vehicle read-model (scalars + projected facts + the composed health badge — see
 * {@see GarageVehicleView::toArray()}) instead of a bare "something changed" notification, so the
 * dashboard renders the fresh state directly, with no follow-up GET and no refetch race.
 *
 * Publishing the WHOLE read-model (not a per-entity delta) keeps the client trivially consistent and
 * absorbs cascades for free: a write that shifts the forecast ships the recomputed health badge in the
 * same frame. The open checklist is NOT part of this frame — it is its own subresource
 * (GET /garage/vehicles/{id}/planned-tasks), which the dashboard refetches on select. Cost is one small
 * JSON payload per write — vehicles are tiny.
 *
 * Two topics per change, matching the frontend's subscriptions:
 *   - the item topic       ({origin}/garage/vehicles/{ulid}) — the open detail card;
 *   - the collection topic ({origin}/garage/vehicles)        — the sidebar parc list.
 *
 * Best-effort, like the render intents: the write already committed, so a hub that is down or
 * untrusted must never turn a successful write into a failure — log and move on.
 */
final class DomainPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly IriConverterInterface $iriConverter,
        private readonly GarageVehicleView $view,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publishVehicle(Vehicle $vehicle, string $event = 'updated'): void
    {
        $itemTopic = $this->iriConverter->getIriFromResource(
            GarageVehicle::class,
            UrlGeneratorInterface::ABS_URL,
            context: ['uri_variables' => ['id' => (string) $vehicle->getId()]],
        );
        $collectionTopic = substr($itemTopic, 0, (int) strrpos($itemTopic, '/'));

        $payload = json_encode([
            'kind' => 'vehicle',
            'event' => $event,
            'vehicle' => (string) $vehicle->getId(),
            'iri' => '/garage/vehicles/'.$vehicle->getId(),
            'resource' => $this->view->toArray($vehicle),
        ], \JSON_THROW_ON_ERROR);

        foreach ([$itemTopic, $collectionTopic] as $topic) {
            try {
                $this->hub->publish(new Update($topic, $payload));
            } catch (\Throwable $e) {
                $this->logger->warning('Mercure publish failed for topic {topic}: {error}', [
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
