<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Agent\Journal\TurnJournal;
use App\Entity\FinderSelection;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;

final readonly class FinderSelections
{
    public function __construct(
        private DocumentManager $documentManager,
        private OwnerContext $owner,
        private TurnJournal $journal,
        private ClockInterface $clock,
    ) {
    }

    public function record(FinderVehicle $vehicle, int $categoryId, ?int $productGroupId, string $clusterId, string $basis, ?string $setAside, ?string $organisationNumber): FinderSelection
    {
        $selection = new FinderSelection(
            owner: $this->owner->current(),
            turnId: $this->journal->currentTurnId(),
            carId: $vehicle->carId,
            carType: $vehicle->carType->value,
            categoryId: $categoryId,
            productGroupId: $productGroupId,
            clusterId: $clusterId,
            basis: $basis,
            setAside: $setAside,
            organisationNumber: $organisationNumber,
            createdAt: $this->clock->now(),
        );
        $this->documentManager->persist($selection);
        $this->documentManager->flush();

        return $selection;
    }
}
