<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A "stop this turn" flag (see TurnCancellations): its presence before `expiresAt` is the whole signal.
 */
#[ODM\Document(collection: 'turn_cancellations')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'ttl_turn_cancellations', expireAfterSeconds: 0)]
class TurnCancellation
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $expiresAt;
}
