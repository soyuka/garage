<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use MongoDB\BSON\Binary;

/**
 * A finder turn's photos between the HTTP request and the worker (see TurnImageStash). Short-lived: the
 * TTL index reaps a batch the worker never got to forget.
 */
#[ODM\Document(collection: 'turn_images')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'ttl_turn_images', expireAfterSeconds: 0)]
class TurnImageBatch
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    /** @var list<array{mimeType: string, bytes: Binary}> */
    #[ODM\Field(type: 'raw')]
    private array $images = [];

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $expiresAt;

    /**
     * @return list<array{mimeType: string, bytes: Binary}>
     */
    public function getImages(): array
    {
        return $this->images;
    }
}
