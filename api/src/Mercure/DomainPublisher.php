<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Agent\Ui\DashboardChannel;
use App\Entity\Vehicle;
use App\Garage\GarageVehicleView;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes the full vehicle read-model to the dashboard on a write, called explicitly from the write
 * processors after they flush. The payload CARRIES the read-model (scalars + projected facts + the
 * composed health badge — see {@see GarageVehicleView::toArray()}), so the dashboard renders the fresh
 * state with no follow-up GET and no refetch race; a write that shifts the forecast ships the
 * recomputed health in the same frame. The open checklist is its own subresource, refetched on select.
 *
 * One topic: the vehicle owner's fleet topic ({@see DashboardChannel::fleetTopic()}), a keyed digest
 * handed out by GET /agent/link with a subscriber token naming it. The update is private, so only that
 * token reads it; the digest keeps the topic itself from naming the owner. A vehicle with no owner is
 * not published.
 *
 * Best-effort, like the render intents: the write already committed, so a hub that is down or
 * untrusted must never turn a successful write into a failure — log and move on.
 */
final readonly class DomainPublisher
{
    public function __construct(
        private HubInterface $hub,
        private DashboardChannel $channel,
        private GarageVehicleView $view,
        private LoggerInterface $logger,
    ) {
    }

    public function publishVehicle(Vehicle $vehicle, string $event = 'updated'): void
    {
        $owner = $vehicle->getOwner();
        if (null === $owner || '' === $owner) {
            return;
        }

        $topic = $this->channel->fleetTopic($owner);
        $payload = json_encode([
            'kind' => 'vehicle',
            'event' => $event,
            'vehicle' => (string) $vehicle->getId(),
            'iri' => '/garage/vehicles/'.(string) $vehicle->getId(),
            'resource' => $this->view->toArray($vehicle),
        ], \JSON_THROW_ON_ERROR);

        try {
            $this->hub->publish(new Update($topic, $payload, private: true));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for the fleet topic: {error}', ['error' => $e->getMessage()]);
        }
    }
}
